<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Contracts\PushProvider;
use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignRecipient;
use App\Modules\Campaigns\Notifications\CampaignMessage;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use App\Modules\Identity\Models\Device;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sends one batch of a campaign on one channel. Only rows still queued are sent, so a
 * retried batch picks up where it stopped. People who reached their frequency cap since
 * the audience was built are skipped here. A failure the provider may recover from leaves
 * the row queued (up to eventapp.campaigns.tries attempts) and asks the job to retry.
 */
class CampaignDelivery
{
    public function __construct(
        private readonly PushProvider $push,
        private readonly SmsProvider $sms,
        private readonly FrequencyCap $caps,
    ) {}

    /**
     * @param  list<string>  $recipientIds
     * @return bool whether some recipients should be retried
     */
    public function deliver(Campaign $campaign, Channel $channel, array $recipientIds, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $recipients = CampaignRecipient::whereIn('id', $recipientIds)
            ->where('status', RecipientStatus::Queued->value)
            ->with('user:'.implode(',', Eligibility::USER_COLUMNS))
            ->get();

        $recipients = $recipients->reject(function (CampaignRecipient $r) use ($now) {
            if ($r->user === null || $r->user->status !== 'active') {
                $this->skip($r, SkipReason::Inactive, $now);

                return true;
            }

            return false;
        });

        $capped = $this->caps->capped($recipients->pluck('user_id')->unique()->values()->all(), $campaign, $now);
        $recipients = $recipients->reject(function (CampaignRecipient $r) use ($capped, $now) {
            if (isset($capped[$r->user_id])) {
                $this->skip($r, SkipReason::FrequencyCap, $now);

                return true;
            }

            return false;
        });

        if ($recipients->isEmpty()) {
            return false;
        }

        $nextEvents = MessageRenderer::usesEventTitle($campaign->title, $campaign->body, $campaign->sms_body)
            ? $this->nextEventTitles($recipients->pluck('user_id')->all(), $now)
            : [];

        return match ($channel) {
            Channel::Push => $this->push($campaign, $recipients, $nextEvents, $now),
            Channel::Sms => $this->sms($campaign, $recipients, $nextEvents, $now),
            Channel::InApp => $this->inApp($campaign, $recipients, $nextEvents, $now),
        };
    }

    /** The SMS text: personalised, with the opt-out line on marketing messages. */
    public static function smsText(Campaign $campaign, string $rendered): string
    {
        return $campaign->isMarketing()
            ? rtrim($rendered)."\n".config('eventapp.campaigns.sms.opt_out_line')
            : $rendered;
    }

    /** @param Collection<int, CampaignRecipient> $recipients */
    private function push(Campaign $campaign, Collection $recipients, array $nextEvents, CarbonImmutable $now): bool
    {
        $image = $campaign->image?->url('full');
        $retry = false;

        // People whose message reads the same share a request; personalised ones get their own.
        $groups = $recipients->groupBy(fn (CampaignRecipient $r) => json_encode($this->content($campaign, $r, $nextEvents)));

        foreach ($groups as $group) {
            [$title, $body, $route] = $this->content($campaign, $group->first(), $nextEvents);
            foreach ($group->chunk((int) config('eventapp.campaigns.push_batch_size')) as $batch) {
                $message = new PushMessage($title ?? '', $body, $route, array_filter([
                    'campaign_id' => $campaign->id,
                    // One recipient: the app can report the open precisely.
                    'recipient_id' => $batch->count() === 1 ? $batch->first()->id : null,
                ]), imageUrl: $image);

                $result = $this->push->send($batch->pluck('user_id')->values()->all(), $message);

                if (! $result->accepted) {
                    $retry = $this->failOrRetry($batch, (string) $result->error, $result->retryable, $now) || $retry;

                    continue;
                }

                $unreachable = array_flip($result->unreachableUserIds);
                foreach ($batch as $r) {
                    if (isset($unreachable[$r->user_id])) {
                        $this->fail($r, 'no_subscribed_device', $now);
                    } else {
                        $this->sent($r, $now, $result->messageId);
                    }
                }
                // The provider no longer knows these devices: stop counting them as reachable.
                if ($unreachable !== []) {
                    Device::whereIn('user_id', array_keys($unreachable))->delete();
                }
            }
        }

        return $retry;
    }

    /** @param Collection<int, CampaignRecipient> $recipients */
    private function sms(Campaign $campaign, Collection $recipients, array $nextEvents, CarbonImmutable $now): bool
    {
        $retry = false;
        foreach ($recipients as $r) {
            $text = self::smsText($campaign, MessageRenderer::render($campaign->sms_body ?: $campaign->body, $r->user, $r->context ?? [], $nextEvents[$r->user_id] ?? null));
            $segments = SmsCounter::count($text)['segments'];

            try {
                $result = $this->sms->send($r->user->phone, $text);
            } catch (SmsDeliveryFailed $e) {
                $retry = $this->failOrRetry(collect([$r]), $e->getMessage(), true, $now) || $retry;

                continue;
            }

            $r->forceFill(['sms_segments' => $segments, 'cost_minor' => SmsCounter::costMinor($segments)]);
            $this->sent($r, $now, $result->messageId);
        }

        return $retry;
    }

    /** @param Collection<int, CampaignRecipient> $recipients */
    private function inApp(Campaign $campaign, Collection $recipients, array $nextEvents, CarbonImmutable $now): bool
    {
        $image = $campaign->image?->url('full');
        foreach ($recipients as $r) {
            [$title, $body, $route] = $this->content($campaign, $r, $nextEvents);
            DB::transaction(function () use ($campaign, $r, $title, $body, $route, $image, $now) {
                $r->user->notifyNow(new CampaignMessage($campaign->id, $r->id, $campaign->category, (string) $title, $body, $route, $image));
                // Written to the inbox is delivered.
                $r->forceFill(['delivered_at' => $now]);
                $this->sent($r, $now, null, RecipientStatus::Delivered);
            });
        }

        return false;
    }

    /** @return array{0: ?string, 1: string, 2: ?string} title, body, route */
    private function content(Campaign $campaign, CampaignRecipient $r, array $nextEvents): array
    {
        $context = $r->context ?? [];
        $event = $nextEvents[$r->user_id] ?? null;

        return [
            $campaign->title === null ? null : MessageRenderer::render($campaign->title, $r->user, $context, $event),
            MessageRenderer::render($campaign->body, $r->user, $context, $event),
            DeepLinks::path($campaign->deep_link, $context),
        ];
    }

    /**
     * Each user's next upcoming event they host, for {event_title}.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    private function nextEventTitles(array $userIds, CarbonImmutable $now): array
    {
        return DB::table('events')
            ->whereIn('host_id', $userIds)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('starts_at', '>', $now)
            ->orderByDesc('starts_at') // the soonest wins in the map below
            ->get(['host_id', 'title'])
            ->mapWithKeys(fn ($e) => [$e->host_id => $e->title])
            ->all();
    }

    private function sent(CampaignRecipient $r, CarbonImmutable $now, ?string $messageId, RecipientStatus $status = RecipientStatus::Sent): void
    {
        $r->forceFill([
            'status' => $status,
            'sent_at' => $now,
            'provider_message_id' => $messageId,
            'attempts' => $r->attempts + 1,
            'error' => null,
        ])->save();
    }

    private function skip(CampaignRecipient $r, SkipReason $reason, CarbonImmutable $now): void
    {
        $r->forceFill(['status' => RecipientStatus::Skipped, 'skip_reason' => $reason, 'updated_at' => $now])->save();
    }

    private function fail(CampaignRecipient $r, string $error, CarbonImmutable $now): void
    {
        $r->forceFill(['status' => RecipientStatus::Failed, 'error' => mb_substr($error, 0, 255), 'attempts' => $r->attempts + 1, 'updated_at' => $now])->save();
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $batch
     * @return bool whether any stay queued for another try
     */
    private function failOrRetry(Collection $batch, string $error, bool $retryable, CarbonImmutable $now): bool
    {
        $tries = (int) config('eventapp.campaigns.tries');
        $retry = false;
        foreach ($batch as $r) {
            if ($retryable && $r->attempts + 1 < $tries) {
                $r->forceFill(['attempts' => $r->attempts + 1, 'error' => mb_substr($error, 0, 255)])->save();
                $retry = true;
            } else {
                $this->fail($r, $error, $now);
            }
        }

        return $retry;
    }
}

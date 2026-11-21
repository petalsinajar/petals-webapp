<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Contracts\PushProvider;
use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Data\AudienceMember;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Jobs\RunCampaign;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Notifications\CampaignMessage;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaLibrary;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A campaign's lifecycle. Status only changes here:
 *
 *   draft → (submit) → pending_approval → (approve) → scheduled → sending → sent
 *                    ↘ scheduled ↗                          ↖ recurring: back to scheduled
 *   draft | pending_approval | scheduled → cancelled
 *
 * Sends above eventapp.campaigns.approval need a super admin other than the author.
 */
class CampaignService
{
    public function __construct(
        private readonly CampaignDispatcher $dispatcher,
        private readonly Eligibility $eligibility,
        private readonly PushProvider $push,
        private readonly SmsProvider $sms,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * The push picture: a ready image the admin uploaded (POST /v1/uploads), attached to the
     * campaign so it isn't pruned. Null removes it.
     */
    public function setImage(Campaign $campaign, ?string $mediaId, User $admin): void
    {
        $image = $this->media->resolveOne($mediaId, $admin, null, 'image_media_id', for: $campaign);
        DB::transaction(function () use ($campaign, $image) {
            $campaign->forceFill(['image_media_id' => $image?->id])->save();
            if ($image) {
                $this->media->attach([$image], $campaign);
            }
            $this->media->releaseExcept($campaign, [$image?->id]);
        });
    }

    /**
     * Audience size, who each channel would reach, and the SMS cost, for the composer and
     * the approval rule. Frequency caps are applied at send time, so the real send can be
     * a little smaller.
     *
     * @return array{audience: int, reachable: array<string, int>, sms_segments: int, sms_cost_minor: int}
     */
    public function estimate(Campaign $campaign): array
    {
        $reachable = array_fill_keys($campaign->channels ?? [], 0);
        $audience = 0;

        if ($people = $campaign->audience()) {
            $people->select('users.id')->chunkById(1000, function ($chunk) use ($campaign, &$reachable, &$audience) {
                $ids = $chunk->pluck('id')->all();
                $audience += count($ids);
                $users = Eligibility::loadUsers($ids);
                foreach ($campaign->channelList() as $channel) {
                    $reachable[$channel->value] += count(array_filter($this->eligibility->check($campaign, $channel, $users, $ids), fn ($r) => $r === null));
                }
            }, 'users.id', 'id');
        }

        $segments = $campaign->sendsOn(Channel::Sms)
            ? SmsCounter::count(CampaignDelivery::smsText($campaign, MessageRenderer::sample($campaign->sms_body ?: $campaign->body)))['segments']
            : 0;

        return [
            'audience' => $audience,
            'reachable' => $reachable,
            'sms_segments' => $segments,
            'sms_cost_minor' => SmsCounter::costMinor($segments, $reachable[Channel::Sms->value] ?? 0),
        ];
    }

    /** @param array{audience: int, reachable: array<string, int>} $estimate */
    public static function needsApproval(array $estimate): bool
    {
        ['audience_over' => $audienceOver, 'sms_over' => $smsOver] = config('eventapp.campaigns.approval');

        return $estimate['audience'] > $audienceOver || ($estimate['reachable'][Channel::Sms->value] ?? 0) > $smsOver;
    }

    /**
     * Send now ($at null) or schedule. "Now" during quiet hours waits for the morning; a
     * schedule inside quiet hours is refused.
     */
    public function submit(Campaign $campaign, User $by, ?CarbonImmutable $at = null, ?CarbonImmutable $now = null): Campaign
    {
        $now ??= CarbonImmutable::now();
        if ($campaign->status !== CampaignStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft can be sent or scheduled.']);
        }
        $this->validateContent($campaign);

        if ($at !== null) {
            if ($at < $now->subMinute()) {
                throw ValidationException::withMessages(['scheduled_at' => 'Pick a time in the future.']);
            }
            if (SendWindow::isQuiet($at)) {
                ['start' => $start, 'end' => $end] = config('eventapp.campaigns.quiet_hours');
                throw ValidationException::withMessages(['scheduled_at' => "Nothing is sent between {$start} and {$end} (".SendWindow::timezone().'). Pick another time.']);
            }
        }

        // Freeze the audience: what's estimated (and approved) is what's sent.
        $campaign->audience_filters = $campaign->segment?->filters ?? [];
        $estimate = $this->estimate($campaign);
        $campaign->forceFill([
            'audience_count' => $estimate['audience'],
            'estimated_sms_cost_minor' => $estimate['sms_cost_minor'],
            'requires_approval' => self::needsApproval($estimate),
            'scheduled_at' => SendWindow::nextAllowed($at ?? $now),
            'created_by' => $campaign->created_by ?? $by->id,
        ]);
        $campaign->status = $campaign->requires_approval ? CampaignStatus::PendingApproval : CampaignStatus::Scheduled;
        $campaign->save();

        // With a sync queue the run has finished by now; read back what it did.
        return $this->dispatchIfDue($campaign, $now) ? $campaign->refresh() : $campaign;
    }

    public function approve(Campaign $campaign, User $by, ?CarbonImmutable $now = null): Campaign
    {
        if (! $by->isSuperAdmin()) {
            throw new AuthorizationException('Only a super admin can approve campaigns.');
        }
        if ($by->id === $campaign->created_by) {
            throw new AuthorizationException('A second admin has to approve this campaign.');
        }
        if ($campaign->status !== CampaignStatus::PendingApproval) {
            throw ValidationException::withMessages(['status' => 'This campaign isn’t waiting for approval.']);
        }

        $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'approved_by' => $by->id, 'approved_at' => $now ?? CarbonImmutable::now()])->save();

        return $this->dispatchIfDue($campaign, $now) ? $campaign->refresh() : $campaign;
    }

    public function cancel(Campaign $campaign): Campaign
    {
        if (! $campaign->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'A campaign that has started sending can’t be cancelled.']);
        }
        $campaign->forceFill(['status' => CampaignStatus::Cancelled])->save();

        return $campaign;
    }

    /** Back to draft to change it; a new submit (and approval) is needed. */
    public function unschedule(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::PendingApproval, CampaignStatus::Scheduled], true)) {
            throw ValidationException::withMessages(['status' => 'Only a scheduled or pending campaign can go back to draft.']);
        }
        $campaign->forceFill(['status' => CampaignStatus::Draft, 'approved_by' => null, 'approved_at' => null, 'requires_approval' => false, 'audience_filters' => null])->save();

        return $campaign;
    }

    /** Claims a due campaign (once, even with concurrent callers) and queues its run. */
    public function dispatchIfDue(Campaign $campaign, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $claimed = Campaign::whereKey($campaign->id)
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->update(['status' => CampaignStatus::Sending->value, 'updated_at' => $now]);

        if ($claimed === 1) {
            $campaign->status = CampaignStatus::Sending;
            RunCampaign::dispatch($campaign->id);
        }

        return $claimed === 1;
    }

    /**
     * Builds the recipients of a claimed campaign and queues delivery. Returns when to try
     * again if it's quiet hours now, else null.
     */
    public function run(Campaign $campaign, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        if ($campaign->status !== CampaignStatus::Sending) {
            return null;
        }
        if (SendWindow::isQuiet($now)) {
            return SendWindow::nextAllowed($now);
        }

        // The frozen filters can still match more people over time (new sign-ups, repeats):
        // a send that has grown past the limits without an approval waits for one.
        if ($campaign->approved_by === null && ! $campaign->isAutomation()) {
            $estimate = $this->estimate($campaign);
            if (self::needsApproval($estimate)) {
                $campaign->forceFill([
                    'status' => CampaignStatus::PendingApproval,
                    'requires_approval' => true,
                    'audience_count' => $estimate['audience'],
                    'estimated_sms_cost_minor' => $estimate['sms_cost_minor'],
                ])->save();

                return null;
            }
        }

        $runKey = $campaign->recurrence
            ? $campaign->scheduled_at->setTimezone(SendWindow::timezone())->toDateString()
            : '';

        $members = $campaign->audience()?->select('users.id')->lazyById(1000, 'users.id', 'id')
            ->map(fn ($u) => new AudienceMember($u->id, $runKey)) ?? [];

        $this->dispatcher->run($campaign, $members, $now);

        if ($campaign->recurrence) {
            $campaign->status = CampaignStatus::Scheduled;
            $campaign->scheduled_at = SendWindow::nextAllowed($campaign->recurrence->next($campaign->scheduled_at));
        } else {
            $campaign->status = CampaignStatus::Sent;
        }
        $campaign->last_run_at = $now;
        $campaign->save();

        return null;
    }

    /**
     * Sends the campaign to the admin themself on every channel it uses, ignoring their
     * preferences and the caps. Nothing is recorded as a recipient.
     *
     * @return array<string, string> channel => what happened
     */
    public function sendTest(Campaign $campaign, User $admin): array
    {
        $this->validateContent($campaign);
        $title = '[Test] '.MessageRenderer::render((string) $campaign->title, $admin);
        $body = MessageRenderer::render($campaign->body, $admin);
        $route = DeepLinks::path($campaign->deep_link);
        $results = [];

        foreach ($campaign->channelList() as $channel) {
            $results[$channel->value] = match ($channel) {
                Channel::Push => ($r = $this->push->send([$admin->id], new PushMessage($title, $body, $route, ['campaign_id' => $campaign->id], imageUrl: $campaign->image?->url('full'))))->accepted
                    ? ($r->unreachableUserIds ? 'Sent, but none of your devices are signed in.' : 'Sent to your devices.')
                    : 'Push failed: '.$r->error,
                Channel::Sms => $this->testSms($campaign, $admin),
                Channel::InApp => (function () use ($admin, $campaign, $title, $body, $route) {
                    $admin->notifyNow(new CampaignMessage($campaign->id, null, $campaign->category, $title, $body, $route));

                    return 'Added to your notifications.';
                })(),
            };
        }

        return $results;
    }

    private function testSms(Campaign $campaign, User $admin): string
    {
        if (blank($admin->phone)) {
            return 'You have no phone number.';
        }
        try {
            $this->sms->send($admin->phone, CampaignDelivery::smsText($campaign, MessageRenderer::render($campaign->sms_body ?: $campaign->body, $admin)));

            return 'Sent to '.$admin->phone.'.';
        } catch (SmsDeliveryFailed $e) {
            return 'SMS failed: '.$e->getMessage();
        }
    }

    private function validateContent(Campaign $campaign): void
    {
        $errors = [];
        if ($campaign->channelList() === []) {
            $errors['channels'] = 'Pick at least one channel.';
        }
        if (! array_key_exists($campaign->category, config('eventapp.campaigns.categories'))) {
            $errors['category'] = 'Pick a category.';
        }
        if (($campaign->sendsOn(Channel::Push) || $campaign->sendsOn(Channel::InApp)) && blank($campaign->title)) {
            $errors['title'] = 'Push and in-app messages need a title.';
        }
        if (blank($campaign->body)) {
            $errors['body'] = 'Write the message.';
        }
        if (! $campaign->isAutomation() && $campaign->segment_id === null) {
            $errors['segment_id'] = 'Pick an audience.';
        }
        $link = $campaign->deep_link;
        if (filled($link['type'] ?? null) && DeepLinks::needsId($link['type']) && blank($link['id'] ?? null) && ! $campaign->isAutomation()) {
            $errors['deep_link.id'] = 'This link needs the id of what it opens.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

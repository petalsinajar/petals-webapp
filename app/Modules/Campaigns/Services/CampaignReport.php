<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * A campaign's numbers from its recipients. Bookings are attributed when a recipient made
 * one within eventapp.campaigns attribution days of their first message.
 */
class CampaignReport
{
    public const ATTRIBUTION_DAYS = 7;

    /**
     * @return array{
     *   audience: int,
     *   channels: array<string, array{total: int, queued: int, sent: int, delivered: int, failed: int, skipped: int, opened: int, clicked: int}>,
     *   skipped: array<string, int>,
     *   sms_cost_minor: int,
     *   bookings: int,
     * }
     */
    public function for(Campaign $campaign): array
    {
        $base = fn () => DB::table('campaign_recipients')->where('campaign_id', $campaign->id);

        $channels = [];
        foreach ($campaign->channelList() as $channel) {
            $channels[$channel->value] = ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0];
        }
        $rows = $base()->groupBy('channel', 'status')
            ->selectRaw('channel, status, count(*) as n, count(opened_at) as opened, count(clicked_at) as clicked')
            ->get();
        foreach ($rows as $row) {
            $c = &$channels[$row->channel];
            $c ??= ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0];
            $c['total'] += $row->n;
            $c[$row->status] += $row->n;
            $c['opened'] += $row->opened;
            $c['clicked'] += $row->clicked;
            unset($c);
        }
        // "Sent" includes what was then confirmed delivered.
        foreach ($channels as &$c) {
            $c['sent'] += $c['delivered'];
        }
        unset($c);

        $skipped = $base()->where('status', RecipientStatus::Skipped->value)
            ->groupBy('skip_reason')->selectRaw('skip_reason, count(*) as n')->pluck('n', 'skip_reason')
            ->mapWithKeys(fn ($n, $reason) => [SkipReason::tryFrom((string) $reason)?->label() ?? (string) $reason => (int) $n])
            ->all();

        // Each reached person's first message, then their bookings in the window after it.
        $firstSent = $base()->whereIn('status', RecipientStatus::reached())
            ->groupBy('user_id')->selectRaw('user_id, min(sent_at) as first_sent');
        $bookings = DB::table('bookings')
            ->joinSub($firstSent, 'reached', 'reached.user_id', '=', 'bookings.customer_id')
            ->whereColumn('bookings.created_at', '>=', 'reached.first_sent')
            ->get(['bookings.created_at', 'reached.first_sent'])
            ->filter(fn ($b) => strtotime($b->created_at) <= strtotime($b->first_sent) + self::ATTRIBUTION_DAYS * 86400)
            ->count();

        return [
            'audience' => (int) $base()->distinct()->count('user_id'),
            'channels' => $channels,
            'skipped' => $skipped,
            'sms_cost_minor' => (int) $base()->where('channel', Channel::Sms->value)->sum('cost_minor'),
            'bookings' => $bookings,
        ];
    }
}

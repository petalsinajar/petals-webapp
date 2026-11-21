<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marketing caps per person across every campaign and channel: at most per_day campaigns
 * since local midnight and per_week in the last 7 days. One campaign on two channels counts
 * once, and the campaign being sent doesn't count against itself.
 */
class FrequencyCap
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, true> user id => true for those at their cap
     */
    public function capped(array $userIds, Campaign $campaign, CarbonImmutable $now): array
    {
        if (! $campaign->isMarketing() || $userIds === []) {
            return [];
        }
        ['per_day' => $perDay, 'per_week' => $perWeek] = config('eventapp.campaigns.frequency_caps');
        $dayStart = $now->setTimezone(SendWindow::timezone())->startOfDay()->utc();
        $weekStart = $now->subDays(7);

        $rows = DB::table('campaign_recipients')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->whereIn('campaign_recipients.user_id', $userIds)
            ->where('campaign_recipients.campaign_id', '!=', $campaign->id)
            ->whereIn('campaign_recipients.status', RecipientStatus::reached())
            ->whereIn('campaigns.category', config('eventapp.campaigns.marketing_categories'))
            ->where('campaign_recipients.sent_at', '>=', min($dayStart, $weekStart))
            ->select('campaign_recipients.user_id', 'campaign_recipients.campaign_id', DB::raw('min(campaign_recipients.sent_at) as first_sent'))
            ->groupBy('campaign_recipients.user_id', 'campaign_recipients.campaign_id')
            ->get();

        $capped = [];
        foreach ($rows->groupBy('user_id') as $userId => $campaigns) {
            $sent = $campaigns->map(fn ($r) => CarbonImmutable::parse($r->first_sent, 'UTC'));
            $today = $sent->filter(fn ($at) => $at >= $dayStart)->count();
            $week = $sent->filter(fn ($at) => $at >= $weekStart)->count();
            if ($today >= $perDay || $week >= $perWeek) {
                $capped[$userId] = true;
            }
        }

        return $capped;
    }
}

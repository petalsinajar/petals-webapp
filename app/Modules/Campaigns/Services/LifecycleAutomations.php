<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Data\AudienceMember;
use App\Modules\Campaigns\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle messages: campaigns with an `automation` key, switched on in Filament. Each run
 * (hourly) finds who's due and hands them to the same pipeline as admin campaigns, so
 * preferences, quiet hours and frequency caps apply. The run key makes each step happen
 * once per person: welcome steps once ever, countdowns once per event and day, inactivity
 * once per spell, the vendor summary once per business and week.
 */
class LifecycleAutomations
{
    public function __construct(private readonly CampaignDispatcher $dispatcher) {}

    /** @return array<string, int> automation => people queued or skipped */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        if (SendWindow::isQuiet($now)) {
            return [];
        }

        $done = [];
        foreach (Campaign::whereNotNull('automation')->where('active', true)->get() as $campaign) {
            $members = $this->members($campaign->automation, $now);
            if ($members === null) {
                continue;
            }
            $totals = $this->dispatcher->run($campaign, $members, $now);
            $campaign->forceFill(['last_run_at' => $now])->save();
            $done[$campaign->automation] = $totals['queued'] + $totals['skipped'];
        }

        return $done;
    }

    /** @return ?iterable<AudienceMember> null for an unknown automation */
    public function members(string $automation, CarbonImmutable $now): ?iterable
    {
        $config = config('eventapp.campaigns.automations');

        if (isset($config['welcome_days'][$automation])) {
            $days = $config['welcome_days'][$automation];

            // Signed up between N and N+1 days ago; once ever.
            return $this->users(fn ($q) => $q
                ->where('users.created_at', '<=', $now->subDays($days))
                ->where('users.created_at', '>', $now->subDays($days + 1)))
                ->map(fn ($u) => new AudienceMember($u->id));
        }

        if (isset($config['inactive'][$automation])) {
            $days = $config['inactive'][$automation];

            // Last seen N to N+7 days ago (room for missed runs); once per spell of inactivity.
            return $this->users(fn ($q) => $q
                ->where('users.last_active_at', '<=', $now->subDays($days))
                ->where('users.last_active_at', '>', $now->subDays($days + 7)), ['users.id', 'users.last_active_at'])
                ->map(fn ($u) => new AudienceMember($u->id, 'inactive:'.CarbonImmutable::parse($u->last_active_at)->toDateString()));
        }

        return match ($automation) {
            'event_countdown' => $this->countdown($now, $config['countdown_days']),
            'vendor_weekly_summary' => $this->vendorSummary($now, (int) $config['vendor_summary_day']),
            default => null,
        };
    }

    /** Hosts and co-hosts of events starting N days from today (local dates). */
    private function countdown(CarbonImmutable $now, array $days): iterable
    {
        $today = $now->setTimezone(SendWindow::timezone())->startOfDay();
        foreach ($days as $n) {
            $from = $today->addDays($n)->utc();
            $events = DB::table('events')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<', $from->addDay())
                ->get(['id', 'host_id', 'title']);

            foreach ($events as $event) {
                $cohosts = DB::table('event_members')->where('event_id', $event->id)->where('role', 'cohost')->whereNotNull('user_id')->pluck('user_id');
                foreach ($cohosts->prepend($event->host_id)->unique() as $userId) {
                    yield new AudienceMember($userId, "event:{$event->id}:{$n}", ['event_id' => $event->id, 'event_title' => $event->title]);
                }
            }
        }
    }

    /** On the summary day: owners and managers of each active business, with last week's requests. */
    private function vendorSummary(CarbonImmutable $now, int $isoDay): iterable
    {
        $local = $now->setTimezone(SendWindow::timezone());
        if ($local->dayOfWeekIso !== $isoDay) {
            return;
        }
        $week = $local->format('o-\WW');

        $vendors = DB::table('vendors')->where('status', 'active')->whereNull('deleted_at')->get(['id', 'name']);
        $requests = DB::table('bookings')->where('created_at', '>=', $now->subDays(7))
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->groupBy('vendor_id')->selectRaw('vendor_id, count(*) as n')->pluck('n', 'vendor_id');

        foreach ($vendors as $vendor) {
            $members = DB::table('vendor_members')->where('vendor_id', $vendor->id)->where('status', 'active')
                ->whereIn('role', ['owner', 'manager'])->pluck('user_id');
            foreach ($members as $userId) {
                yield new AudienceMember($userId, "vendor:{$vendor->id}:{$week}", [
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->name,
                    'week_bookings' => (int) ($requests[$vendor->id] ?? 0),
                ]);
            }
        }
    }

    /** Active, non-admin users (the same base as any audience), filtered by $where. */
    private function users(callable $where, array $columns = ['users.id'])
    {
        return tap(SegmentQuery::users([]), $where)->select($columns)->lazyById(1000, 'users.id', 'id');
    }
}

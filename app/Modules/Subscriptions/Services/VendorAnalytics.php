<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The vendor's business numbers, limited to what its plan's analytics level includes
 * (eventapp.analytics: each level adds to the ones before). Metrics outside the level
 * are left out entirely, not computed and hidden.
 */
class VendorAnalytics
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return list<string> */
    public function allowedMetrics(string $level): array
    {
        $levels = config('eventapp.analytics.levels');
        $upTo = array_slice($levels, 0, array_search($level, $levels, true) + 1);

        return array_merge(...array_map(fn (string $l) => config("eventapp.analytics.metrics.{$l}", []), $upTo));
    }

    /** @return array{level: string, days: int, metrics: array<string, mixed>, locked: list<string>} */
    public function report(Vendor $vendor, int $days): array
    {
        $level = $this->entitlements->analyticsLevel($vendor);
        $allowed = $this->allowedMetrics($level);
        $all = $this->allowedMetrics(last(config('eventapp.analytics.levels')));
        $since = CarbonImmutable::now()->subDays($days);

        $metrics = [];
        foreach ($allowed as $metric) {
            $metrics[$metric] = $this->{str($metric)->camel()->toString()}($vendor, $since);
        }

        return [
            'level' => $level,
            'days' => $days,
            'metrics' => $metrics,
            // What a better plan would show, for an upgrade prompt.
            'locked' => array_values(array_diff($all, $allowed)),
        ];
    }

    private function bookings(Vendor $vendor, CarbonImmutable $since): int
    {
        return DB::table('bookings')->where('vendor_id', $vendor->id)->where('created_at', '>=', $since)->count();
    }

    private function completedBookings(Vendor $vendor, CarbonImmutable $since): int
    {
        return DB::table('bookings')->where('vendor_id', $vendor->id)->where('status', 'completed')->where('completed_at', '>=', $since)->count();
    }

    private function rating(Vendor $vendor): array
    {
        return ['avg' => $vendor->rating_avg, 'count' => $vendor->rating_count];
    }

    private function revenueMinor(Vendor $vendor, CarbonImmutable $since): int
    {
        return (int) DB::table('ledger_entries')->where('vendor_id', $vendor->id)->where('type', 'vendor_earning')
            ->where('created_at', '>=', $since)->sum('amount_minor');
    }

    private function acceptanceRate(Vendor $vendor): ?float
    {
        return $vendor->acceptance_rate;
    }

    private function responseTimeMinutes(Vendor $vendor): ?int
    {
        return $vendor->response_time_minutes;
    }

    private function cancellationRate(Vendor $vendor): ?float
    {
        return $vendor->cancellation_rate;
    }

    /** @return list<array{item_id: string, title: string, qty: int, bookings: int}> */
    private function topItems(Vendor $vendor, CarbonImmutable $since): array
    {
        return DB::table('booking_lines as l')->join('bookings as b', 'b.id', '=', 'l.booking_id')
            ->where('b.vendor_id', $vendor->id)->where('b.created_at', '>=', $since)
            ->whereNotIn('b.status', ['declined', 'expired'])
            ->groupBy('l.item_id')
            ->selectRaw('l.item_id, MAX(l.title_snapshot) AS title, SUM(l.qty) AS qty, COUNT(DISTINCT b.id) AS bookings')
            ->orderByDesc('bookings')->orderByDesc('qty')->limit(5)->get()
            ->map(fn ($r) => ['item_id' => $r->item_id, 'title' => $r->title, 'qty' => (int) $r->qty, 'bookings' => (int) $r->bookings])
            ->all();
    }

    private function posts(Vendor $vendor, CarbonImmutable $since)
    {
        return DB::table('posts')->where(['author_type' => 'vendor', 'author_id' => $vendor->id])
            ->whereNull('deleted_at')->where('created_at', '>=', $since);
    }

    /** @return array{posts: int, likes: int, comments: int, saves: int} */
    private function postEngagement(Vendor $vendor, CarbonImmutable $since): array
    {
        $row = $this->posts($vendor, $since)
            ->selectRaw('COUNT(*) AS posts, COALESCE(SUM(likes_count),0) AS likes, COALESCE(SUM(comments_count),0) AS comments, COALESCE(SUM(saves_count),0) AS saves')
            ->first();

        return array_map('intval', (array) $row);
    }

    private function shopTaps(Vendor $vendor, CarbonImmutable $since): int
    {
        return (int) $this->posts($vendor, $since)->sum('shop_taps_count');
    }

    /** @return array{sent: int, opened: int, booked: int} */
    private function suggestions(Vendor $vendor, CarbonImmutable $since): array
    {
        $base = fn () => DB::table('recommendations')->where('vendor_id', $vendor->id)->where('sent_at', '>=', $since);

        return [
            'sent' => $base()->count(),
            'opened' => $base()->whereNotNull('opened_at')->count(),
            'booked' => $base()->where('status', 'booked')->count(),
        ];
    }

    /** @return list<array{date: string, bookings: int}> */
    private function daily(Vendor $vendor, CarbonImmutable $since): array
    {
        $tz = config('eventapp.business_timezone');

        return DB::table('bookings')->where('vendor_id', $vendor->id)->where('created_at', '>=', $since)
            ->pluck('created_at')
            ->countBy(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz)->toDateString())
            ->sortKeys()->map(fn (int $n, string $date) => ['date' => $date, 'bookings' => $n])->values()->all();
    }

    /** Share of customers in the window who booked more than once. */
    private function repeatCustomers(Vendor $vendor, CarbonImmutable $since): ?float
    {
        $counts = DB::table('bookings')->where('vendor_id', $vendor->id)->where('created_at', '>=', $since)
            ->whereNotNull('customer_id')->groupBy('customer_id')->selectRaw('COUNT(*) AS n')->pluck('n');

        return $counts->isEmpty() ? null : round($counts->filter(fn ($n) => $n > 1)->count() / $counts->count(), 4);
    }
}

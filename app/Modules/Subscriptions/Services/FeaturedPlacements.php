<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Blackout;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Models\FeaturedCredit;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Featured weeks. A vendor spends a credit on a week (Monday to Monday, business timezone);
 * while it runs, the vendor may fill a Sponsored slot, which is always labelled, kept apart
 * from the organic results, and only shown when the vendor matches the search: the event
 * type, the location (its service area covers it) and the date (not blacked out).
 */
class FeaturedPlacements
{
    public function use(FeaturedCredit $credit, string $weekStart): FeaturedCredit
    {
        $tz = config('eventapp.business_timezone');
        $start = CarbonImmutable::parse($weekStart, $tz)->startOfDay();
        $thisWeek = CarbonImmutable::now($tz)->startOfWeek();

        $error = match (true) {
            $credit->used_at !== null => 'This featured week has already been used.',
            $credit->expires_at->isPast() => 'This featured week has expired.',
            ! $start->isMonday() => 'Featured weeks start on a Monday.',
            $start->lessThan($thisWeek) => 'Choose this week or a later one.',
            $start->greaterThan($thisWeek->addWeeks(config('eventapp.subscriptions.featured_weeks_ahead'))) => 'Choose a week in the next '.config('eventapp.subscriptions.featured_weeks_ahead').' weeks.',
            $start->greaterThan($credit->expires_at) => 'Use it in a week before it expires.',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['week_start' => $error]);
        }

        return DB::transaction(function () use ($credit, $start) {
            $end = $start->addWeek();
            $clash = FeaturedCredit::where('vendor_id', $credit->vendor_id)->whereKeyNot($credit->id)
                ->where('placement_starts_at', '<', $end)->where('placement_ends_at', '>', $start)->lockForUpdate()->exists();
            if ($clash) {
                throw ValidationException::withMessages(['week_start' => 'You are already featured that week.']);
            }

            $credit->forceFill(['used_at' => now(), 'placement_starts_at' => $start, 'placement_ends_at' => $end])->save();

            return $credit;
        });
    }

    /**
     * Featured vendors for a search, at most the configured number of slots, in a fair
     * rotating order.
     *
     * @param  list<string>  $days  Y-m-d days the customer needs (empty: any)
     * @param  list<string>|null  $onlyVendorIds  restrict to vendors that already match (e.g. suggestions)
     * @return Collection<int, Vendor>
     */
    public function sponsored(?string $eventType, ?float $lat, ?float $lng, array $days = [], ?array $onlyVendorIds = null, ?string $category = null): Collection
    {
        $now = CarbonImmutable::now();
        $running = FeaturedCredit::runningAt($now)->distinct()->pluck('vendor_id');
        if ($running->isEmpty()) {
            return collect();
        }

        $query = Vendor::query()
            ->whereIn('vendors.id', $running)
            ->where('status', 'active')
            ->when($onlyVendorIds !== null, fn ($q) => $q->whereIn('vendors.id', $onlyVendorIds))
            ->when($eventType, fn ($q, $type) => $q->whereJsonContains('event_types', $type))
            ->when($category, fn ($q, $c) => $q->whereJsonContains('categories', $c));

        if ($lat !== null && $lng !== null) {
            Geo::vendorsServing($query, $lat, $lng);
        }

        $vendors = $query->get();
        if ($days !== []) {
            $vendors = $vendors->reject(fn (Vendor $v) => Blackout::where('vendor_id', $v->id)->overlapping(min($days), max($days))->exists());
        }

        // Rotate by the hour so every featured vendor gets its turn.
        $seed = (int) $now->format('YmdH');

        return $vendors
            ->sortBy(fn (Vendor $v) => crc32($v->id.$seed))
            ->take((int) config('eventapp.visibility.sponsored_slots'))
            ->values();
    }

    /** @return array<string, mixed> the labelled card for a Sponsored slot */
    public static function card(Vendor $vendor, array $summary): array
    {
        return [...$summary, 'sponsored' => true, 'label' => 'Sponsored'];
    }
}

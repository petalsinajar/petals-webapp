<?php

namespace App\Modules\Recommendations\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingLine;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Recommendations\Data\VendorMatch;
use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use App\Modules\Recommendations\Models\Recommendation;
use App\Modules\Recommendations\Notifications\VendorSuggestions;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suggests available vendors to hosts for the categories their event still needs.
 *
 * One run for one event sends at most one notification: the category with the fewest
 * suggestions so far, and its best few vendors. Everything is read through the catalog
 * and availability contracts, never the Catalog module's tables.
 */
class RecommendationService
{
    /** Bookings in these states don't count as "this category is covered". */
    private const LAPSED = [BookingStatus::Declined->value, BookingStatus::Expired->value, BookingStatus::Cancelled->value];

    private readonly RecommendationRules $rules;

    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly AvailabilityStore $store,
        ?RecommendationRules $rules = null,
    ) {
        $this->rules = $rules ?? RecommendationRules::fromConfig();
    }

    public function rules(): RecommendationRules
    {
        return $this->rules;
    }

    /**
     * Send the next suggestions for an event if it's due.
     *
     * @return Collection<int, Recommendation> what was sent (empty when nothing was due)
     */
    public function run(Event $event, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $event->loadMissing('host');

        $reason = $this->dueReason($event, $now);
        if (! $reason) {
            return collect();
        }

        foreach ($this->categoriesToSuggest($event) as $category => $room) {
            $matches = array_slice($this->matches($event, $category, $now), 0, min($room, $this->rules->config('max_vendors_per_notification')));
            if ($matches !== []) {
                return $this->send($event, $category, $reason, $matches, $now);
            }
        }

        return collect();
    }

    /** The stage to send for, or null if the event isn't due (opted out, too soon, quiet hours, weekly cap). */
    public function dueReason(Event $event, CarbonImmutable $now): ?RecommendationReason
    {
        if (! $this->rules->config('enabled') || ! $this->isCandidate($event, $now)) {
            return null;
        }

        $reason = $this->rules->stage($event->starts_at, CarbonImmutable::instance($event->created_at), $now);

        if (! $reason
            || $this->rules->isQuiet($now, $event->timezone)
            || $this->notificationsThisWeek($event, $now) >= $this->rules->config('max_notifications_per_event_per_week')) {
            return null;
        }

        return $reason;
    }

    /** Upcoming, still being planned, and the host hasn't turned suggestions off. */
    public function isCandidate(Event $event, CarbonImmutable $now): bool
    {
        return $event->starts_at->greaterThan($now)
            && ! in_array($event->status, ['cancelled', 'completed'], true)
            && $event->host
            && self::wantsSuggestions($event->host);
    }

    public static function wantsSuggestions(User $user): bool
    {
        return data_get($user->settings, 'notify.suggestions', true) !== false;
    }

    /**
     * Categories this event type usually needs that nothing has been booked for yet.
     *
     * @return list<string>
     */
    public function missingCategories(Event $event): array
    {
        $required = $this->rules->config("required_categories.{$event->type}", []);
        if ($required === []) {
            return [];
        }

        $itemIds = BookingLine::query()
            ->whereIn('booking_id', Booking::select('id')->where('event_id', $event->id)->whereNotIn('status', self::LAPSED))
            ->distinct()->pluck('item_id')->all();
        $booked = array_unique(array_values($this->catalog->categoriesOf($itemIds)));

        return array_values(array_diff($required, $booked));
    }

    /**
     * Vendors who can supply a category for this event, best first. Excludes vendors the host
     * has already booked and, unless $includeSuggested, vendors already suggested (or dismissed).
     *
     * @return list<VendorMatch>
     */
    public function matches(Event $event, string $category, CarbonImmutable $now, bool $includeSuggested = false): array
    {
        $suggested = Recommendation::where('event_id', $event->id)
            ->when($includeSuggested, fn ($q) => $q->where('status', RecommendationStatus::Dismissed->value));
        $excluded = $suggested->pluck('vendor_id')
            ->merge(Booking::where('event_id', $event->id)->whereNotIn('status', self::LAPSED)->pluck('vendor_id'))
            ->unique()->all();

        $vendors = Vendor::query()
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->whereJsonContains('event_types', $event->type)
            ->whereNotIn('id', $excluded)
            // With a known venue, only vendors whose own radius covers it. Without one, anyone qualifies.
            ->when($event->venue_lat !== null && $event->venue_lng !== null,
                fn ($q) => Geo::vendorsServing($q, (float) $event->venue_lat, (float) $event->venue_lng))
            ->get()
            ->mapWithKeys(fn (Vendor $vendor) => [$vendor->id => [$vendor, $this->distanceKm($event, $vendor)]]);

        if ($vendors->isEmpty()) {
            return [];
        }

        $days = ReservationWindow::days($event->starts_at, $event->ends_at);
        $cheapest = $this->cheapestAvailable($this->catalog->activeVariantsInCategory($category, $vendors->keys()->all()), $days);
        $qty = $this->rules->estimateQty($category, $event->guest_count);
        $recent = $this->recentRecommendationCounts(array_keys($cheapest), $now);

        $matches = [];
        foreach ($cheapest as $vendorId => $variant) {
            [$vendor, $km] = $vendors->get($vendorId);
            $estimate = $variant->unitPriceMinor * max($qty, $variant->minQty);

            if (! $this->rules->fitsBudget($estimate, $event->budget_minor)) {
                continue;
            }

            $components = [
                'rating' => $this->rules->ratingScore($vendor->rating_avg, $vendor->rating_count),
                'experience' => $this->rules->experienceScore($vendor->completed_bookings_count),
                'distance' => $this->rules->distanceScore($km, $vendor->service_radius_km),
                'price' => $this->rules->priceScore($estimate, $event->budget_minor),
                'fairness' => $this->rules->fairnessScore($recent[$vendorId] ?? 0),
                'plan' => $this->rules->planScore((float) $vendor->visibility_boost),
            ];

            $matches[] = new VendorMatch($vendor, $category, $this->rules->score($components),
                $variant->unitPriceMinor, $variant->currency, $km, $components);
        }

        usort($matches, fn (VendorMatch $a, VendorMatch $b) => [$b->score, $a->vendor->id] <=> [$a->score, $b->vendor->id]);

        return $matches;
    }

    /** Distinct notifications (batches) sent for this event in the last 7 days. */
    public function notificationsThisWeek(Event $event, CarbonImmutable $now): int
    {
        return Recommendation::where('event_id', $event->id)
            ->where('sent_at', '>', $now->subWeek())
            ->distinct()->count('batch_id');
    }

    /**
     * Missing categories that still have room, the least-suggested first (so one category
     * can't take every notification), each with how many more vendors it may get.
     *
     * @return array<string, int>
     */
    private function categoriesToSuggest(Event $event): array
    {
        $missing = $this->missingCategories($event);
        if ($missing === []) {
            return [];
        }

        $stats = Recommendation::where('event_id', $event->id)
            ->selectRaw("category_id, count(*) as total, sum(case when status = 'dismissed' then 1 else 0 end) as dismissed")
            ->groupBy('category_id')->get()->keyBy('category_id');

        $room = [];
        foreach ($missing as $category) {
            $total = (int) ($stats[$category]->total ?? 0);
            $dismissed = (int) ($stats[$category]->dismissed ?? 0);
            $left = $this->rules->config('max_vendors_per_category') - $total;

            if ($left > 0 && $dismissed < $this->rules->config('stop_category_after_dismissals')) {
                $room[$category] = ['left' => $left, 'total' => $total];
            }
        }

        // Stable sort keeps the config's priority order among equally suggested categories.
        uasort($room, fn (array $a, array $b) => $a['total'] <=> $b['total']);

        return array_map(fn (array $r) => $r['left'], $room);
    }

    /**
     * Each vendor's cheapest variant with stock left on every day of the event.
     *
     * @param  list<VariantSnapshot>  $variants
     * @param  list<string>  $days
     * @return array<string, VariantSnapshot> keyed by vendor id
     */
    private function cheapestAvailable(array $variants, array $days): array
    {
        $best = [];
        foreach ($variants as $variant) {
            $remaining = $this->store->remaining($variant->variantId, $variant->quantity, $days);
            if (min($remaining) < max(1, $variant->minQty)) {
                continue;
            }

            $current = $best[$variant->vendorId] ?? null;
            if (! $current || $variant->unitPriceMinor < $current->unitPriceMinor) {
                $best[$variant->vendorId] = $variant;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $vendorIds
     * @return array<string, int>
     */
    private function recentRecommendationCounts(array $vendorIds, CarbonImmutable $now): array
    {
        if ($vendorIds === []) {
            return [];
        }

        return Recommendation::whereIn('vendor_id', $vendorIds)
            ->where('created_at', '>', $now->subDays($this->rules->config('fairness_window_days')))
            ->selectRaw('vendor_id, count(*) as total')->groupBy('vendor_id')
            ->pluck('total', 'vendor_id')->map(fn ($n) => (int) $n)->all();
    }

    private function distanceKm(Event $event, Vendor $vendor): ?float
    {
        if ($event->venue_lat === null || $event->venue_lng === null || $vendor->base_lat === null || $vendor->base_lng === null) {
            return null;
        }

        return Geo::distanceKm($vendor->base_lat, $vendor->base_lng, $event->venue_lat, $event->venue_lng);
    }

    /**
     * Record one batch and notify the host.
     *
     * @param  list<VendorMatch>  $matches
     * @return Collection<int, Recommendation>
     */
    private function send(Event $event, string $category, RecommendationReason $reason, array $matches, CarbonImmutable $now): Collection
    {
        $batchId = (string) Str::ulid();

        try {
            $recommendations = DB::transaction(fn () => collect($matches)->map(fn (VendorMatch $match) => Recommendation::create([
                'event_id' => $event->id,
                'vendor_id' => $match->vendor->id,
                'category_id' => $category,
                'reason' => $reason,
                'score' => $match->score,
                'status' => RecommendationStatus::Pending,
                'batch_id' => $batchId,
                'details' => $match->details(),
            ])));
        } catch (UniqueConstraintViolationException) {
            // Another run got there first; it will have sent its own notification.
            return collect();
        }

        $event->host->notify(new VendorSuggestions($event, $category, $reason, array_map(fn (VendorMatch $m) => [
            'id' => $m->vendor->id,
            'handle' => $m->vendor->handle,
            'name' => $m->vendor->name,
            'logo_url' => $m->vendor->logo_url,
        ], $matches)));

        Recommendation::whereIn('id', $recommendations->pluck('id'))
            ->update(['status' => RecommendationStatus::Sent->value, 'sent_at' => $now]);

        return $recommendations->each->refresh();
    }
}

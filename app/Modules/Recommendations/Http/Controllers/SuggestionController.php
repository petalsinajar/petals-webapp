<?php

namespace App\Modules\Recommendations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Models\Category;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Http\Resources\VendorSummary;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Recommendations\Data\VendorMatch;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use App\Modules\Recommendations\Http\Resources\SuggestionResource;
use App\Modules\Recommendations\Models\Recommendation;
use App\Modules\Recommendations\Services\RecommendationService;
use App\Modules\Subscriptions\Services\FeaturedPlacements;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuggestionController extends Controller
{
    private const VENDORS_PER_NEED = 3;

    /** GET /v1/events/{event}/suggestions — vendors suggested to the host, newest first. */
    public function index(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('manage', $event);

        $suggestions = Recommendation::where('event_id', $event->id)
            ->whereIn('status', [RecommendationStatus::Sent->value, RecommendationStatus::Opened->value, RecommendationStatus::Booked->value])
            ->with('vendor')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(30);

        return Paginated::response($suggestions, SuggestionResource::class);
    }

    /**
     * GET /v1/events/{event}/needs — categories the event still needs, each with the best few
     * vendors free on its dates. Live (not limited to what was sent), for the Discover tab.
     * Featured vendors among the matches also come back in `sponsored`, labelled.
     */
    public function needs(Event $event, RecommendationService $recommendations, FeaturedPlacements $placements): JsonResponse
    {
        Gate::authorize('manage', $event);

        $missing = $event->starts_at->isFuture() ? $recommendations->missingCategories($event) : [];
        $names = Category::whereIn('id', $missing)->pluck('name', 'id');
        $now = CarbonImmutable::now();

        return response()->json([
            'event_id' => $event->id,
            'data' => collect($missing)->map(fn (string $category) => [
                'category_id' => $category,
                'category_name' => $names[$category] ?? Str::headline($category),
                ...$this->withSponsored(collect($recommendations->matches($event, $category, $now, includeSuggested: true)), $event, $placements),
            ])->values(),
        ]);
    }

    /**
     * The organic matches, and the featured vendors among all matches (they already fit the
     * event type, venue, dates and budget) for the labelled Sponsored slot.
     *
     * @param  Collection<int, VendorMatch>  $matches
     * @return array{vendors: Collection<int, array<string, mixed>>, sponsored: Collection<int, array<string, mixed>>}
     */
    private function withSponsored($matches, Event $event, FeaturedPlacements $placements): array
    {
        $sponsored = $matches->isEmpty() ? collect() : $placements->sponsored(
            $event->type, null, null,
            ReservationWindow::days($event->starts_at, $event->ends_at),
            $matches->map(fn (VendorMatch $m) => $m->vendor->id)->all(),
        );

        return [
            'vendors' => $matches->take(self::VENDORS_PER_NEED)
                ->map(fn (VendorMatch $match) => [
                    'vendor' => VendorSummary::of($match->vendor),
                    'from_price_minor' => $match->fromPriceMinor,
                    'currency' => $match->currency,
                    'distance_km' => $match->distanceKm === null ? null : round($match->distanceKm, 1),
                ])->values(),
            'sponsored' => $sponsored->map(fn (Vendor $v) => FeaturedPlacements::card($v, VendorSummary::of($v)))->values(),
        ];
    }

    /** POST /v1/suggestions/{recommendation}/open */
    public function open(Recommendation $recommendation): SuggestionResource
    {
        Gate::authorize('manage', $recommendation->event);

        $recommendation->opened_at ??= now()->toImmutable();
        if (in_array($recommendation->status, [RecommendationStatus::Pending, RecommendationStatus::Sent], true)) {
            $recommendation->status = RecommendationStatus::Opened;
        }
        $recommendation->save();

        return new SuggestionResource($recommendation->load('vendor'));
    }

    /** POST /v1/suggestions/{recommendation}/dismiss — "not interested": never suggested again for this event. */
    public function dismiss(Recommendation $recommendation): SuggestionResource
    {
        Gate::authorize('manage', $recommendation->event);

        if ($recommendation->status !== RecommendationStatus::Booked) {
            $recommendation->forceFill(['status' => RecommendationStatus::Dismissed, 'dismissed_at' => now()])->save();
        }

        return new SuggestionResource($recommendation->load('vendor'));
    }

    /** GET /v1/vendors/{vendor}/recommendation-stats?days= */
    public function vendorStats(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);
        $data = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);

        $row = Recommendation::where('vendor_id', $vendor->id)
            ->where('status', '!=', RecommendationStatus::Pending->value)
            ->when($data['days'] ?? null, fn ($q, $days) => $q->where('created_at', '>=', now()->subDays($days)))
            ->selectRaw('count(*) as recommended')
            ->selectRaw('sum(case when opened_at is not null then 1 else 0 end) as opened')
            ->selectRaw("sum(case when status = 'booked' then 1 else 0 end) as booked")
            ->selectRaw("sum(case when status = 'dismissed' then 1 else 0 end) as dismissed")
            ->toBase()->first();

        return response()->json([
            'vendor_id' => $vendor->id,
            'days' => $data['days'] ?? null,
            'recommended' => (int) $row->recommended,
            'opened' => (int) $row->opened,
            'booked' => (int) $row->booked,
            'dismissed' => (int) $row->dismissed,
        ]);
    }
}

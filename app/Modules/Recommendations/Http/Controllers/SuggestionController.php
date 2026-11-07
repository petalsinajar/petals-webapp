<?php

namespace App\Modules\Recommendations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use App\Modules\Recommendations\Http\Resources\SuggestionResource;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SuggestionController extends Controller
{
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

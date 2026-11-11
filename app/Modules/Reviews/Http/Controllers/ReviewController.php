<?php

namespace App\Modules\Reviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Http\Resources\ReviewResource;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Reviews\Services\ReviewSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Verified, two-way reviews of completed bookings. See ReviewService for the rules.
 */
class ReviewController extends Controller
{
    private const WITH = ['booking.event', 'media', 'customer', 'vendor'];

    public function __construct(private readonly ReviewService $reviews) {}

    /** POST /v1/bookings/{booking}/reviews { rating, aspects?, body?, media?, item_ids? } */
    public function store(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        $review = $this->reviews->submit($booking, $request->user(), $data);

        return (new ReviewResource($review->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** PATCH /v1/reviews/{review} — by the author, within the edit window, before publication. */
    public function update(Request $request, Review $review): ReviewResource
    {
        $data = $request->validate($this->rules(required: false));

        return new ReviewResource($this->reviews->update($review, $request->user(), $data)->load(self::WITH));
    }

    /**
     * GET /v1/bookings/{booking}/reviews — my review, and whether the other side has
     * reviewed. Their review is only shown once published (and a vendor's review of the
     * customer never to the customer).
     */
    public function forBooking(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();
        $side = $this->reviews->directionFor($booking, $user);
        abort_if($side === null, 403, 'Only the customer or the vendor\'s team can see this.');

        $reviews = Review::where('booking_id', $booking->id)->with(self::WITH)->get()->keyBy(fn (Review $r) => $r->direction->value);
        $mine = $reviews->get($side->value);
        $theirs = $reviews->get($side->other()->value);
        $until = $this->reviews->reviewableUntil($booking);

        $showTheirs = $theirs
            && $theirs->status === ReviewStatus::Published
            && $theirs->direction === ReviewDirection::CustomerToVendor;

        return response()->json([
            'direction' => $side->value,
            'can_review' => ! $mine && $until && now()->lessThanOrEqualTo($until),
            'review_until' => $until?->toIso8601String(),
            'mine' => $mine ? (new ReviewResource($mine))->resolve($request) : null,
            'other_side' => [
                'submitted' => (bool) $theirs && $theirs->status !== ReviewStatus::Removed,
                'review' => $showTheirs ? (new ReviewResource($theirs))->resolve($request) : null,
            ],
        ]);
    }

    /** GET /v1/vendors/{vendor}/reviews?sort=recent|highest|lowest&rating=&with_photos= */
    public function forVendor(Request $request, Vendor $vendor): JsonResponse
    {
        return $this->list($request, fn () => Review::published()->ofVendor()->where('vendor_id', $vendor->id), ReviewDirection::CustomerToVendor);
    }

    /** GET /v1/items/{item}/reviews — reviews that covered this item. */
    public function forItem(Request $request, Item $item): JsonResponse
    {
        return $this->list($request, fn () => Review::published()->ofVendor()->whereJsonContains('item_ids', $item->id), ReviewDirection::CustomerToVendor);
    }

    /**
     * GET /v1/customers/{user}/vendor-reviews — what vendors said about a customer. Only for
     * the teams of vendors the customer currently asks to book (a pending or accepted booking).
     */
    public function forCustomer(Request $request, User $user): JsonResponse
    {
        abort_if($this->reviews->vendorsAskedBy($user->id, $request->user())->isEmpty(), 403,
            'Vendor reviews of a customer are only shown to vendors they are booking.');

        return $this->list($request, fn () => Review::published()
            ->where('direction', ReviewDirection::VendorToCustomer)
            ->where('customer_id', $user->id), ReviewDirection::VendorToCustomer);
    }

    /** POST /v1/reviews/{review}/reply { body } — owners and managers; editable for a while. */
    public function reply(Request $request, Review $review): ReviewResource
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.config('eventapp.reviews.reply_max')]]);

        return new ReviewResource($this->reviews->reply($review, $request->user(), $data['body'])->load(self::WITH));
    }

    /** POST /v1/reviews/{review}/report { reason, details? } */
    public function report(Request $request, Review $review): JsonResponse
    {
        abort_unless($this->reviews->canSee($review, $request->user()), 404);

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(config('eventapp.reviews.removal_reasons')))],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = $this->reviews->report($review, $request->user(), $data['reason'], $data['details'] ?? null);

        return response()->json(['id' => $report->id, 'status' => $report->status], $report->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @param  callable(): Builder<Review>  $base  the published reviews in scope, before filters
     */
    private function list(Request $request, callable $base, ReviewDirection $direction): JsonResponse
    {
        $filters = $request->validate([
            'sort' => ['sometimes', Rule::in(['recent', 'highest', 'lowest'])],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'with_photos' => ['sometimes', 'boolean'],
        ]);

        $query = $base()
            ->when($filters['rating'] ?? null, fn (Builder $q, $r) => $q->where('rating', (int) $r))
            ->when($request->boolean('with_photos'), fn (Builder $q) => $q->withPhotos())
            ->with(self::WITH);

        match ($filters['sort'] ?? 'recent') {
            'highest' => $query->orderByDesc('rating'),
            'lowest' => $query->orderBy('rating'),
            default => null,
        };
        $page = $query->orderByDesc('published_at')->orderByDesc('id')->cursorPaginate(20);

        return response()->json([
            // Over all of them, not just the filtered ones.
            'summary' => $request->query('cursor') ? null : ReviewSummary::of($base(), $direction->aspects()),
            'data' => ReviewResource::collection($page->items())->resolve($request),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $config = config('eventapp.reviews');
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'rating' => [...$sometimes, 'required', 'integer', 'between:1,5'],
            'aspects' => ['sometimes', 'nullable', 'array'],
            'aspects.*' => ['integer', 'between:1,5'],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.$config['body_max']],
            'media' => ['sometimes', 'nullable', 'array', 'max:'.$config['max_media']],
            'media.*' => ['ulid', 'distinct'],
            'item_ids' => ['sometimes', 'nullable', 'array'],
            'item_ids.*' => ['ulid', 'distinct'],
        ];
    }
}

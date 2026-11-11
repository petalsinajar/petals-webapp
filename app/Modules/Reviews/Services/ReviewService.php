<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaLibrary;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Notifications\ReviewReceived;
use App\Modules\Reviews\Notifications\VendorReplied;
use App\Modules\Social\Models\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Reviews of completed bookings, one per side. A new review is pending (hidden) until the
 * other side has reviewed too, or the blind window has passed (see PublishDueReviews); then
 * the booking's reviews are published together, so neither side can answer the other's.
 */
class ReviewService
{
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly RatingAggregator $ratings,
    ) {}

    /** Which side $user reviews this booking as, or null if they're on neither side. */
    public function directionFor(Booking $booking, User $user): ?ReviewDirection
    {
        return match (true) {
            $booking->customer_id !== null && $booking->customer_id === $user->id => ReviewDirection::CustomerToVendor,
            $booking->customer_id !== null && Gate::forUser($user)->allows('operate', $booking->vendor) => ReviewDirection::VendorToCustomer,
            default => null,
        };
    }

    /** Until when this booking can be reviewed, or null if it can't (not completed). */
    public function reviewableUntil(Booking $booking): ?\DateTimeInterface
    {
        if ($booking->status !== BookingStatus::Completed || ! $booking->completed_at) {
            return null;
        }

        return $booking->completed_at->addDays(config('eventapp.reviews.window_days'));
    }

    /**
     * @param  array{rating: int, aspects?: array<string, int>|null, body?: ?string, media?: list<string>|null, item_ids?: list<string>|null}  $data  validated
     */
    public function submit(Booking $booking, User $author, array $data): Review
    {
        $direction = $this->directionFor($booking, $author)
            ?? throw new AuthorizationException('Only the customer or the vendor\'s team can review this booking.');

        $until = $this->reviewableUntil($booking);
        if (! $until) {
            throw ValidationException::withMessages(['booking' => 'Only a completed booking can be reviewed.']);
        }
        if (now()->greaterThan($until)) {
            throw ValidationException::withMessages(['booking' => 'The time to review this booking has passed.']);
        }

        $vendor = $direction === ReviewDirection::VendorToCustomer ? $booking->vendor : null;
        $media = $this->media->resolve($data['media'] ?? [], $author, $vendor, ['image'], field: 'media');

        $review = DB::transaction(function () use ($booking, $author, $direction, $data, $media) {
            // Both sides submitting at once: the second one sees the first and publishes both.
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (Review::where(['booking_id' => $booking->id, 'direction' => $direction])->exists()) {
                throw ValidationException::withMessages(['booking' => 'You have already reviewed this booking.']);
            }

            $review = Review::create([
                'booking_id' => $booking->id,
                'direction' => $direction,
                'author_id' => $author->id,
                'vendor_id' => $booking->vendor_id,
                'customer_id' => $booking->customer_id,
                'rating' => $data['rating'],
                'aspects' => $this->aspects($direction, $data['aspects'] ?? null),
                'body' => $data['body'] ?? null,
                'item_ids' => $this->itemIds($booking, $direction, $data['item_ids'] ?? null),
                'edited_until' => now()->addHours(config('eventapp.reviews.edit_hours')),
            ]);
            $this->media->attach($media, $review);

            return $review;
        });

        if (Review::where(['booking_id' => $booking->id, 'direction' => $direction->other()])->exists()) {
            $this->publishBooking($booking->id);
        }

        return $review->refresh();
    }

    /**
     * The author's changes, within the edit window and before it's published.
     *
     * @param  array{rating?: int, aspects?: array<string, int>|null, body?: ?string, media?: list<string>|null, item_ids?: list<string>|null}  $data  validated
     */
    public function update(Review $review, User $author, array $data): Review
    {
        if ($review->author_id !== $author->id) {
            throw new AuthorizationException('Only the author can edit this review.');
        }
        if (! $review->isEditable()) {
            throw ValidationException::withMessages(['review' => $review->status === ReviewStatus::Pending
                ? 'Reviews can only be edited for '.config('eventapp.reviews.edit_hours').' hours.'
                : 'A published review can no longer be edited.']);
        }

        $vendor = $review->direction === ReviewDirection::VendorToCustomer ? $review->vendor : null;
        $media = array_key_exists('media', $data)
            ? $this->media->resolve($data['media'] ?? [], $author, $vendor, ['image'], field: 'media', for: $review)
            : null;

        DB::transaction(function () use ($review, $data, $media) {
            $review->fill(array_intersect_key($data, array_flip(['rating', 'body'])));
            if (array_key_exists('aspects', $data)) {
                $review->aspects = $this->aspects($review->direction, $data['aspects']);
            }
            if (array_key_exists('item_ids', $data)) {
                $review->item_ids = $this->itemIds($review->booking, $review->direction, $data['item_ids']);
            }
            $review->save();

            if ($media !== null) {
                $this->media->attach($media, $review);
                $this->media->releaseExcept($review, $media->modelKeys());
            }
        });

        return $review->refresh();
    }

    /**
     * Publish the booking's pending reviews together, recompute ratings and tell each
     * reviewed side. Safe to repeat.
     */
    public function publishBooking(string $bookingId): void
    {
        $published = DB::transaction(function () use ($bookingId) {
            $pending = Review::where('booking_id', $bookingId)
                ->where('status', ReviewStatus::Pending)
                ->lockForUpdate()->get();

            foreach ($pending as $review) {
                $review->forceFill(['status' => ReviewStatus::Published, 'published_at' => now()])->save();
            }

            return $pending;
        });

        foreach ($published as $review) {
            if ($review->direction === ReviewDirection::CustomerToVendor) {
                $this->ratings->forReview($review);
            }
            Notification::send($this->subjectsOf($review), new ReviewReceived($review));
        }
    }

    /** The vendor's one public reply to a published review, editable for a short while. */
    public function reply(Review $review, User $actor, string $body): Review
    {
        if (! Gate::forUser($actor)->allows('manage', $review->vendor)) {
            throw new AuthorizationException('Only the vendor\'s owners and managers can reply.');
        }
        if ($review->direction !== ReviewDirection::CustomerToVendor || $review->status !== ReviewStatus::Published) {
            throw ValidationException::withMessages(['review' => 'You can only reply to a published review of your business.']);
        }
        if (! $review->replyEditable()) {
            throw ValidationException::withMessages(['body' => 'Replies can only be edited for '.config('eventapp.reviews.reply_edit_hours').' hours.']);
        }

        $first = $review->vendor_replied_at === null;
        $review->forceFill([
            'vendor_reply' => $body,
            'vendor_replied_at' => $review->vendor_replied_at ?? now(),
        ])->save();

        if ($first && $this->wantsReviewNotices($review->author)) {
            $review->author->notify(new VendorReplied($review));
        }

        return $review;
    }

    /** Into the platform team's reports queue. One open report per person per review. */
    public function report(Review $review, User $reporter, string $reason, ?string $details): Report
    {
        return Report::firstOrCreate(
            ['reporter_id' => $reporter->id, 'target_type' => $review->getMorphClass(), 'target_id' => $review->id, 'status' => 'open'],
            ['reason' => $reason, 'details' => $details],
        );
    }

    /** Whether $user may see this review: public ones by anyone, private ones by vendor teams the customer asks to book. */
    public function canSee(Review $review, User $user): bool
    {
        if ($review->author_id === $user->id || $user->is_admin) {
            return true;
        }
        if ($review->status !== ReviewStatus::Published) {
            return false;
        }

        return $review->direction === ReviewDirection::CustomerToVendor
            || $this->vendorsAskedBy($review->customer_id, $user)->isNotEmpty();
    }

    /**
     * Vendors $member belongs to that $customerId currently asks to book (a pending or accepted
     * booking): those teams may read what other vendors said about the customer.
     *
     * @return Collection<int, string> vendor ids
     */
    public function vendorsAskedBy(string $customerId, User $member): Collection
    {
        return Booking::where('customer_id', $customerId)
            ->whereIn('status', [BookingStatus::PendingVendor, BookingStatus::Accepted])
            ->whereIn('vendor_id', $member->vendorMemberships()->select('vendor_id'))
            ->distinct()->pluck('vendor_id');
    }

    /** Who a review is about: the vendor's owners and managers, or the customer. */
    public function subjectsOf(Review $review): Collection
    {
        $people = $review->direction === ReviewDirection::CustomerToVendor
            ? $review->vendor->managers()->get()
            : collect([$review->customer])->filter();

        return $people->filter(fn (User $u) => $this->wantsReviewNotices($u))->values();
    }

    public function wantsReviewNotices(?User $user): bool
    {
        return $user !== null && data_get($user->settings, 'notify.reviews', true) !== false;
    }

    /** @param  array<string, int>|null  $aspects */
    private function aspects(ReviewDirection $direction, ?array $aspects): ?array
    {
        if (! $aspects) {
            return null;
        }
        $unknown = array_diff(array_keys($aspects), $direction->aspects());
        if ($unknown) {
            throw ValidationException::withMessages(['aspects' => 'Unknown aspects: '.implode(', ', $unknown).'.']);
        }

        return array_map('intval', $aspects);
    }

    /**
     * Items the customer reviewed, from the booking's lines (all of them when not given).
     * Vendor → customer reviews are about the person, not items.
     *
     * @param  list<string>|null  $ids
     * @return list<string>|null
     */
    private function itemIds(Booking $booking, ReviewDirection $direction, ?array $ids): ?array
    {
        if ($direction === ReviewDirection::VendorToCustomer) {
            return null;
        }

        $booked = $booking->lines()->distinct()->pluck('item_id')->all();
        if ($ids === null) {
            return array_values($booked);
        }

        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        if (array_diff($ids, $booked)) {
            throw ValidationException::withMessages(['item_ids' => 'Only items from this booking can be reviewed.']);
        }

        return $ids;
    }
}

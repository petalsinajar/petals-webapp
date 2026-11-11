<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Social\Models\Report;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The platform team's side (admin panel). A review is removed only for breaking the content
 * rules (eventapp.reviews.removal_reasons), never for being negative, and the row is kept
 * with who removed it and why, so it can be restored.
 */
class ReviewModeration
{
    public function __construct(private readonly RatingAggregator $ratings) {}

    public function remove(Review $review, User $admin, string $reason, ?string $note = null): Review
    {
        if (! array_key_exists($reason, config('eventapp.reviews.removal_reasons'))) {
            throw new InvalidArgumentException("Unknown removal reason [{$reason}].");
        }

        DB::transaction(function () use ($review, $admin, $reason, $note) {
            $review->forceFill([
                'status' => ReviewStatus::Removed,
                'removed_reason' => $reason,
                'removed_note' => $note,
                'removed_by' => $admin->id,
                'removed_at' => now(),
            ])->save();

            $this->openReports($review)->update(['status' => 'actioned']);
        });

        $this->recompute($review);

        return $review;
    }

    /** Back to where it was: published if it had been, otherwise pending. */
    public function restore(Review $review): Review
    {
        $review->forceFill([
            'status' => $review->published_at ? ReviewStatus::Published : ReviewStatus::Pending,
            'removed_reason' => null,
            'removed_note' => null,
            'removed_by' => null,
            'removed_at' => null,
        ])->save();

        $this->recompute($review);

        return $review;
    }

    /** Reports looked at and left standing. */
    public function dismissReports(Review $review): void
    {
        $this->openReports($review)->update(['status' => 'dismissed']);
    }

    private function openReports(Review $review)
    {
        return Report::where(['target_type' => $review->getMorphClass(), 'target_id' => $review->id, 'status' => 'open']);
    }

    private function recompute(Review $review): void
    {
        if ($review->direction === ReviewDirection::CustomerToVendor) {
            $this->ratings->forReview($review);
        }
    }
}

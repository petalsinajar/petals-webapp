<?php

namespace App\Modules\Reviews\Console;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Notifications\ReviewReminder;
use App\Modules\Reviews\Services\ReviewService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Hourly. Publishes reviews whose blind window has passed (the other side never reviewed),
 * and reminds whoever hasn't reviewed a completed booking yet, 1 and 7 days after completion.
 */
class SweepReviews extends Command
{
    protected $signature = 'reviews:sweep';

    protected $description = 'Publish double-blind reviews past their window and send review reminders';

    public function handle(ReviewService $reviews): int
    {
        $published = 0;
        Review::where('status', ReviewStatus::Pending)
            ->where('created_at', '<=', now()->subDays(config('eventapp.reviews.blind_days')))
            ->distinct()->pluck('booking_id')
            ->each(function (string $bookingId) use ($reviews, &$published) {
                $reviews->publishBooking($bookingId);
                $published++;
            });

        $reminded = $this->remind($reviews);

        $this->info("Published reviews on {$published} bookings; sent {$reminded} reminders.");

        return self::SUCCESS;
    }

    /**
     * For each side of a booking still open for review, the latest stage that is due is sent
     * once. A missed run sends only that latest stage, not every one it skipped.
     */
    private function remind(ReviewService $reviews): int
    {
        $stages = collect(config('eventapp.reviews.reminder_days'))->sortDesc()->values();
        $windowDays = config('eventapp.reviews.window_days');
        $sent = 0;

        Booking::where('status', BookingStatus::Completed)
            ->whereNotNull('customer_id')
            ->whereBetween('completed_at', [now()->subDays($windowDays), now()->subDays($stages->min())])
            ->with('customer', 'vendor')
            ->chunkById(200, function (Collection $bookings) use ($reviews, $stages, &$sent) {
                $reviewed = Review::whereIn('booking_id', $bookings->modelKeys())->get(['booking_id', 'direction'])
                    ->map(fn (Review $r) => $r->booking_id.':'.$r->direction->value)->flip();
                $reminded = DB::table('review_reminders')->whereIn('booking_id', $bookings->modelKeys())
                    ->get()->groupBy(fn ($r) => $r->booking_id.':'.$r->direction);

                foreach ($bookings as $booking) {
                    $due = $stages->first(fn (int $d) => $booking->completed_at->addDays($d)->lessThanOrEqualTo(now()));

                    foreach (ReviewDirection::cases() as $direction) {
                        $key = $booking->id.':'.$direction->value;
                        $alreadySent = $reminded->get($key, collect())->contains(fn ($r) => $r->stage_days >= $due);

                        if ($due === null || $reviewed->has($key) || $alreadySent) {
                            continue;
                        }
                        if ($this->claim($booking, $direction, $due)) {
                            $sent += $this->send($reviews, $booking, $direction, $due);
                        }
                    }
                }
            });

        return $sent;
    }

    /** Record the reminder first; a concurrent run that loses the insert sends nothing. */
    private function claim(Booking $booking, ReviewDirection $direction, int $stage): bool
    {
        try {
            DB::table('review_reminders')->insert([
                'booking_id' => $booking->id, 'direction' => $direction->value, 'stage_days' => $stage, 'created_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function send(ReviewService $reviews, Booking $booking, ReviewDirection $direction, int $stage): int
    {
        $people = $direction === ReviewDirection::CustomerToVendor
            ? collect([$booking->customer])
            : $booking->vendor->managers()->get();

        $people = $people->filter(fn (?User $u) => $reviews->wantsReviewNotices($u));
        Notification::send($people, new ReviewReminder($booking, $direction, $stage));

        return $people->isEmpty() ? 0 : 1;
    }
}

<?php

namespace App\Modules\Bookings\Console;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use Illuminate\Console\Command;

/**
 * Hourly. Returned bookings with no dispute past the window become completed,
 * which books the vendor's earnings and the platform commission in the ledger.
 */
class CompleteReturnedBookings extends Command
{
    protected $signature = 'bookings:complete-returned';

    protected $description = 'Complete returned bookings after the dispute window';

    public function handle(BookingService $bookings): int
    {
        $cutoff = now()->subHours(config('eventapp.bookings.dispute_window_hours'));
        $count = 0;

        Booking::where('status', BookingStatus::Returned)
            ->where('returned_at', '<=', $cutoff)
            ->chunkById(100, function ($chunk) use ($bookings, &$count) {
                foreach ($chunk as $booking) {
                    $bookings->complete($booking, null, 'No issues reported');
                    $count++;
                }
            });

        $this->info("Completed {$count} bookings.");

        return self::SUCCESS;
    }
}

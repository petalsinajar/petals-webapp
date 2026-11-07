<?php

namespace App\Modules\Bookings\Console;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Catalog\Availability\AvailabilityStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs every minute. Releases holds that ran out (cart abandoned, vendor didn't respond,
 * deposit not paid) and expires the bookings they belonged to.
 *
 * A TTL index is deliberately not used: deleting the document would skip decrementing
 * the day counters.
 */
class ReleaseExpiredHolds extends Command
{
    protected $signature = 'inventory:release-expired-holds';

    protected $description = 'Release expired stock holds and expire their bookings';

    public function handle(AvailabilityStore $store, BookingService $bookings): int
    {
        $released = 0;
        $expired = 0;
        $rounds = 0;

        do {
            $holds = $store->expiredHolds(CarbonImmutable::now());

            foreach ($holds as $hold) {
                $released += $store->release($hold->id) ? 1 : 0;

                if (! $hold->bookingId) {
                    continue;
                }

                $booking = Booking::find($hold->bookingId);
                if ($booking && in_array($booking->status, [BookingStatus::PendingVendor, BookingStatus::Accepted], true)) {
                    $bookings->expire($booking);
                    $expired++;
                }
            }
        } while (count($holds) > 0 && ++$rounds < 50);

        $this->info("Released {$released} holds, expired {$expired} bookings.");

        return self::SUCCESS;
    }
}

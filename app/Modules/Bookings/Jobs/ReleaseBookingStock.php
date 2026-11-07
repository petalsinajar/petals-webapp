<?php

namespace App\Modules\Bookings\Jobs;

use App\Modules\Catalog\Availability\AvailabilityStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives a booking's stock back in MongoDB after its SQL status changed.
 * Queued with retries so a brief MongoDB outage can't leave stock stuck. Releasing is
 * idempotent, so retries are safe.
 */
class ReleaseBookingStock implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [5, 15, 60, 300];

    public function __construct(public readonly string $bookingId) {}

    public function handle(AvailabilityStore $store): void
    {
        $store->releaseForBooking($this->bookingId);
    }
}

<?php

namespace App\Modules\Bookings\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking was placed (after the checkout committed). Other modules listen to this
 * instead of reaching into checkout, e.g. Recommendations attributes bookings to suggestions.
 */
class BookingCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $bookingId,
        public readonly string $eventId,
        public readonly string $vendorId,
    ) {}
}

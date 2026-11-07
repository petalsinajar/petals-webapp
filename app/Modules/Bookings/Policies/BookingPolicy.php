<?php

namespace App\Modules\Bookings\Policies;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Identity\Models\User;

class BookingPolicy
{
    /** The customer, any member of the vendor's team, or an event host. */
    public function view(User $user, Booking $booking): bool
    {
        return $user->is_admin
            || $booking->customer_id === $user->id
            || $user->roleIn($booking->vendor_id) !== null
            || ($booking->event && $booking->event->isHostedBy($user));
    }
}

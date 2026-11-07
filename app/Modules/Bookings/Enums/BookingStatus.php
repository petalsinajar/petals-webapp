<?php

namespace App\Modules\Bookings\Enums;

enum BookingStatus: string
{
    case PendingVendor = 'pending_vendor';
    case Accepted = 'accepted';           // awaiting deposit
    case Confirmed = 'confirmed';         // deposit paid
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';         // in use at the event
    case Returned = 'returned';
    case Completed = 'completed';         // vendor paid out
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    /** Allowed moves. Anything not listed here is rejected. */
    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::PendingVendor => [self::Accepted, self::Declined, self::Expired, self::Cancelled],
            self::Accepted => [self::Confirmed, self::Expired, self::Cancelled],
            self::Confirmed => [self::OutForDelivery, self::Delivered, self::Cancelled],
            self::OutForDelivery => [self::Delivered],
            self::Delivered => [self::Returned, self::Disputed],
            self::Returned => [self::Completed, self::Disputed],
            self::Disputed => [self::Completed],
            default => [],
        }, true);
    }

    /** Stock is still held for the booking in these states. */
    public function holdsStock(): bool
    {
        return in_array($this, [self::PendingVendor, self::Accepted, self::Confirmed, self::OutForDelivery, self::Delivered], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Expired, self::Cancelled], true);
    }
}

<?php

namespace App\Modules\Catalog\Availability;

enum ReservationKind: string
{
    /** Temporary: cart, pending vendor response or awaiting deposit. Expires on its own. */
    case Hold = 'hold';

    /** Paid booking. Stays until the items are returned. */
    case Booking = 'booking';

    /** Vendor-entered: phone/walk-in order, maintenance, lost stock. */
    case Block = 'block';
}

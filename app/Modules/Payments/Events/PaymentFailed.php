<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;

/** A payment that isn't for a checkout failed (declined card, abandoned charge). */
final class PaymentFailed
{
    public function __construct(public readonly Payment $payment) {}
}

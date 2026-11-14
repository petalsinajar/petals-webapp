<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\Payment;

/**
 * A payment that isn't for a checkout (e.g. purpose "subscription") succeeded. Dispatched
 * once per payment, inside the transaction that marks it paid, so listeners commit with it.
 */
final class PaymentSucceeded
{
    public function __construct(
        public readonly Payment $payment,
        public readonly WebhookResult $result,
    ) {}
}

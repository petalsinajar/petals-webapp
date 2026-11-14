<?php

namespace App\Modules\Subscriptions\Listeners;

use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionService;

/**
 * Subscription payments from the payment webhook. Runs inside the transaction that marks
 * the payment paid (or failed), which only happens once per payment.
 */
class ApplySubscriptionPayment
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(PaymentSucceeded|PaymentFailed $event): void
    {
        if ($event->payment->purpose !== 'subscription') {
            return;
        }

        if ($event instanceof PaymentFailed) {
            $this->subscriptions->paymentFailed($event->payment);

            return;
        }

        $intent = SubscriptionPayment::where('payment_id', $event->payment->id)->first();
        if ($intent) {
            $this->subscriptions->activate($intent, $event->result);
        }
    }
}

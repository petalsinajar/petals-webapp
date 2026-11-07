<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;

/**
 * Wraps the payment provider so it can be swapped (Paystack, Flutterwave, Hubtel...).
 * The app never sees card or wallet details: the provider's hosted checkout collects them.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Start a payment and return where to send the customer.
     *
     * @return array{authorization_url: string|null, reference: string}
     */
    public function initialize(Payment $payment, User $payer): array;

    /** Verify and parse a webhook. Returns null if the signature is invalid. */
    public function parseWebhook(Request $request): ?WebhookResult;

    /** Refund part or all of a successful payment. */
    public function refund(Payment $payment, int $amountMinor): void;
}

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

    /**
     * Charge a saved card authorization (renewals) without the payer present. The result may
     * be final at once; a webhook for the same reference may follow and must be harmless.
     *
     * @return array{reference: string, status: 'success'|'failed'|'pending'}
     */
    public function chargeAuthorization(Payment $payment, User $payer, string $authorizationCode): array;

    /** Verify and parse a webhook. Returns null if the signature is invalid. */
    public function parseWebhook(Request $request): ?WebhookResult;

    /** Refund part or all of a successful payment. */
    public function refund(Payment $payment, int $amountMinor): void;
}

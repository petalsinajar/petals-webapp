<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack: card and mobile money through hosted checkout.
 * Amounts are sent in the currency's minor unit (pesewas for GHS).
 * Webhooks are signed with HMAC-SHA512 of the raw body using the secret key.
 */
class PaystackGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'paystack';
    }

    public function initialize(Payment $payment, User $payer): array
    {
        $response = $this->client()->post('/transaction/initialize', array_filter([
            'email' => $payer->email ?: $payer->id.'@customers.eventapp.invalid',
            'amount' => $payment->amount_minor,
            'currency' => $payment->currency,
            'reference' => $payment->provider_ref,
            'callback_url' => config('eventapp.payments.paystack.callback_url'),
            'channels' => ['mobile_money', 'card'],
            'metadata' => ['payment_id' => $payment->id, 'checkout_id' => $payment->checkout_id],
        ]));

        if (! $response->successful() || ! $response->json('status')) {
            throw new RuntimeException('Payment provider could not start the payment.');
        }

        return [
            'authorization_url' => $response->json('data.authorization_url'),
            'reference' => $response->json('data.reference', $payment->provider_ref),
        ];
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $secret = (string) config('eventapp.payments.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature');

        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return null;
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        return new WebhookResult(
            reference: (string) ($data['reference'] ?? ''),
            succeeded: $event === 'charge.success' && ($data['status'] ?? null) === 'success',
            amountMinor: isset($data['amount']) ? (int) $data['amount'] : null,
            method: $data['channel'] ?? null,
            raw: $request->all(),
            relevant: in_array($event, ['charge.success', 'charge.failed'], true),
        );
    }

    public function refund(Payment $payment, int $amountMinor): void
    {
        $response = $this->client()->post('/refund', [
            'transaction' => $payment->provider_ref,
            'amount' => $amountMinor,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Refund failed at the payment provider.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('eventapp.payments.paystack.base_url'))
            ->withToken((string) config('eventapp.payments.paystack.secret_key'))
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false);
    }
}

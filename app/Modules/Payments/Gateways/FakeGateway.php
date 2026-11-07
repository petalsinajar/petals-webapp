<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Local development and tests. Complete a payment with
 * POST /v1/dev/payments/{payment}/succeed (only registered in local/testing).
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function initialize(Payment $payment, User $payer): array
    {
        return [
            'authorization_url' => url("/v1/dev/payments/{$payment->id}/succeed"),
            'reference' => 'fake_'.$payment->id,
        ];
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        return new WebhookResult(
            reference: (string) $request->input('reference'),
            succeeded: $request->input('status', 'success') === 'success',
            amountMinor: $request->integer('amount_minor') ?: null,
            method: $request->input('method', 'momo'),
            raw: $request->all(),
        );
    }

    public function refund(Payment $payment, int $amountMinor): void
    {
        Log::info('Fake refund', ['payment' => $payment->id, 'amount_minor' => $amountMinor]);
    }
}

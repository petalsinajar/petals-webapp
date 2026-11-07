<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /** POST /v1/webhooks/payments — the only thing that marks money as received. */
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentService $payments): JsonResponse
    {
        $result = $gateway->parseWebhook($request);

        if (! $result) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payments->handleWebhook($result);

        return response()->json(['received' => true]);
    }

    /** POST /v1/dev/payments/{payment}/succeed — local and testing only (fake gateway). */
    public function devSucceed(Payment $payment, PaymentService $payments): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $payments->handleWebhook(new WebhookResult(
            reference: $payment->provider_ref,
            succeeded: true,
            amountMinor: $payment->amount_minor,
            method: 'momo',
            raw: ['dev' => true],
        ));

        return response()->json(['status' => $payment->refresh()->status]);
    }
}

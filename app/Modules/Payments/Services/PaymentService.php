<?php

namespace App\Modules\Payments\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\Checkout;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\InsufficientStockException;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly BookingService $bookings,
        private readonly AvailabilityStore $store,
        private readonly CatalogReader $catalog,
    ) {}

    /**
     * Start paying for a checkout. Amounts are always computed on the server.
     *
     * purpose: deposit — deposits for accepted bookings
     *          full    — everything outstanding on accepted and confirmed bookings
     *          balance — the rest on confirmed bookings
     *
     * @return array{payment: Payment, authorization_url: string|null}
     */
    public function start(Checkout $checkout, User $payer, string $purpose, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey ?: (string) Str::uuid();

        if ($existing = Payment::where('idempotency_key', $key)->first()) {
            abort_unless($existing->checkout_id === $checkout->id, 409, 'Idempotency key already used.');

            return ['payment' => $existing, 'authorization_url' => $existing->raw['authorization_url'] ?? null];
        }

        $allocations = $this->allocations($checkout, $purpose);
        $amount = array_sum($allocations);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['purpose' => 'Nothing to pay yet. Waiting for vendors to accept.']);
        }

        $payment = Payment::create([
            'checkout_id' => $checkout->id,
            'payer_id' => $payer->id,
            'purpose' => $purpose,
            'amount_minor' => $amount,
            'currency' => $checkout->currency,
            'allocations' => $allocations,
            'provider' => $this->gateway->name(),
            'provider_ref' => 'EA-'.Str::upper(Str::random(16)),
            'idempotency_key' => $key,
            'status' => 'pending',
        ]);

        $started = $this->gateway->initialize($payment, $payer);
        $payment->forceFill([
            'provider_ref' => $started['reference'],
            'raw' => ['authorization_url' => $started['authorization_url']],
        ])->save();

        return ['payment' => $payment, 'authorization_url' => $started['authorization_url']];
    }

    /**
     * Start a payment that isn't for a checkout (purpose "subscription"). The module that owns
     * the purpose listens for PaymentSucceeded / PaymentFailed.
     *
     * @return array{payment: Payment, authorization_url: string|null}
     */
    public function startStandalone(User $payer, string $purpose, int $amountMinor, string $currency, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey ?: (string) Str::uuid();

        if ($existing = Payment::where('idempotency_key', $key)->first()) {
            return ['payment' => $existing, 'authorization_url' => $existing->raw['authorization_url'] ?? null];
        }

        $payment = $this->standalonePayment($payer, $purpose, $amountMinor, $currency, $key);
        $started = $this->gateway->initialize($payment, $payer);
        $payment->forceFill([
            'provider_ref' => $started['reference'],
            'raw' => ['authorization_url' => $started['authorization_url']],
        ])->save();

        return ['payment' => $payment, 'authorization_url' => $started['authorization_url']];
    }

    /**
     * Charge a saved card without the payer present. A final answer is applied at once, the
     * same way a webhook would be; a later webhook for it is then a no-op.
     */
    public function chargeSaved(User $payer, string $purpose, int $amountMinor, string $currency, string $authorizationCode, string $idempotencyKey): Payment
    {
        if ($existing = Payment::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $payment = $this->standalonePayment($payer, $purpose, $amountMinor, $currency, $idempotencyKey);
        $charged = $this->gateway->chargeAuthorization($payment, $payer, $authorizationCode);
        $payment->forceFill(['provider_ref' => $charged['reference'], 'method' => 'card'])->save();

        if ($charged['status'] !== 'pending') {
            $this->handleWebhook(new WebhookResult(
                reference: $payment->provider_ref,
                succeeded: $charged['status'] === 'success',
                amountMinor: $payment->amount_minor,
                method: 'card',
                raw: ['charge_authorization' => $charged['status']],
            ));
        }

        return $payment->refresh();
    }

    private function standalonePayment(User $payer, string $purpose, int $amountMinor, string $currency, string $key): Payment
    {
        return Payment::create([
            'checkout_id' => null,
            'payer_id' => $payer->id,
            'purpose' => $purpose,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'allocations' => [],
            'provider' => $this->gateway->name(),
            'provider_ref' => 'EA-'.Str::upper(Str::random(16)),
            'idempotency_key' => $key,
            'status' => 'pending',
        ]);
    }

    /** Called from the provider webhook. Safe to call more than once for the same payment. */
    public function handleWebhook(WebhookResult $result): void
    {
        if (! $result->relevant || $result->reference === '') {
            return;
        }

        $confirmedBookings = DB::transaction(function () use ($result) {
            /** @var Payment|null $payment */
            $payment = Payment::where('provider_ref', $result->reference)->lockForUpdate()->first();

            if (! $payment || $payment->status !== 'pending') {
                return []; // unknown or already processed
            }

            if (! $result->succeeded) {
                $payment->forceFill(['status' => 'failed', 'raw' => [...($payment->raw ?? []), 'webhook' => $result->raw]])->save();
                if ($payment->checkout_id === null) {
                    event(new PaymentFailed($payment));
                }

                return [];
            }

            if ($result->amountMinor !== null && $result->amountMinor !== $payment->amount_minor) {
                Log::warning('Payment amount mismatch', ['payment' => $payment->id, 'expected' => $payment->amount_minor, 'got' => $result->amountMinor]);
                $payment->forceFill(['status' => 'failed', 'raw' => $result->raw])->save();
                if ($payment->checkout_id === null) {
                    event(new PaymentFailed($payment));
                }

                return [];
            }

            $payment->forceFill([
                'status' => 'succeeded',
                'method' => $result->method,
                'raw' => [...($payment->raw ?? []), 'webhook' => $result->raw],
                'paid_at' => now(),
            ])->save();

            // Not for a checkout: the module that started it takes it from here, in this transaction.
            if ($payment->checkout_id === null) {
                event(new PaymentSucceeded($payment, $result));

                return [];
            }

            $touched = [];
            foreach ($payment->allocations as $bookingId => $amount) {
                $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    continue;
                }

                $booking->increment('paid_minor', $amount);
                LedgerEntry::record('customer_payment', $amount, $payment->currency, $booking->id, $booking->vendor_id, $payment->id,
                    "Payment {$payment->provider_ref}", "payment:{$payment->id}:{$booking->id}");
                $touched[] = $booking->id;
            }

            $paid = Booking::where('checkout_id', $payment->checkout_id)->get();
            $payment->checkout->update([
                'status' => $paid->every(fn (Booking $b) => $b->balanceDueMinor() === 0 || $b->status->isFinal()) ? 'paid' : 'partially_paid',
            ]);

            return $touched;
        });

        foreach ($confirmedBookings as $bookingId) {
            $this->confirmStock(Booking::with('lines')->findOrFail($bookingId));
        }
    }

    /**
     * Payment arrived: hold → booking reservation, then mark confirmed. If the hold already
     * expired, try to reserve again; if the stock is gone, cancel with a full refund.
     */
    private function confirmStock(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Accepted || $booking->depositOutstandingMinor() > 0) {
            return;
        }

        foreach ($booking->lines as $line) {
            if ($line->reservation_id && $this->store->confirm($line->reservation_id)) {
                continue;
            }

            try {
                [$reservation] = $this->store->reserveMany([new ReservationRequest(
                    vendorId: $booking->vendor_id,
                    itemId: $line->item_id,
                    variantId: $line->variant_id,
                    qty: $line->qty,
                    capacity: $this->catalog->variant($line->item_id, $line->variant_id)?->quantity ?? 0,
                    start: $booking->window_start->subHours($booking->policy_snapshot['buffer_hours'] ?? 0),
                    end: $booking->window_end->addHours($booking->policy_snapshot['buffer_hours'] ?? 0),
                    kind: ReservationKind::Booking,
                    bookingId: $booking->id,
                )]);
                $line->update(['reservation_id' => $reservation->id]);
            } catch (InsufficientStockException) {
                Log::warning('Stock gone after late payment; cancelling with full refund', ['booking' => $booking->id]);
                $this->bookings->transition($booking, BookingStatus::Cancelled, null, 'Items were no longer available when payment arrived');
                app(RefundService::class)->refundBooking($booking->refresh(), $booking->paid_minor, 'Stock unavailable after late payment');

                return;
            }
        }

        $this->bookings->confirmAfterPayment($booking);
    }

    /** @return array<string, int> booking_id => amount_minor */
    private function allocations(Checkout $checkout, string $purpose): array
    {
        $allocations = [];

        foreach ($checkout->bookings()->get() as $booking) {
            $amount = match ($purpose) {
                'deposit' => $booking->status === BookingStatus::Accepted ? $booking->depositOutstandingMinor() : 0,
                'full' => in_array($booking->status, [BookingStatus::Accepted, BookingStatus::Confirmed], true) ? $booking->balanceDueMinor() : 0,
                'balance' => $booking->status === BookingStatus::Confirmed ? $booking->balanceDueMinor() : 0,
                default => 0,
            };

            if ($amount > 0) {
                $allocations[$booking->id] = $amount;
            }
        }

        return $allocations;
    }
}

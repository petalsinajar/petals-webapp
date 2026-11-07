<?php

namespace App\Modules\Payments\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Refunds a booking by working back through the successful payments that paid for it.
 */
class RefundService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function refundBooking(Booking $booking, int $amountMinor, string $memo): int
    {
        $remaining = min($amountMinor, $booking->paid_minor - $booking->refunded_minor);
        if ($remaining <= 0 || ! $booking->checkout_id) {
            return 0;
        }

        $refunded = 0;
        $payments = Payment::where('checkout_id', $booking->checkout_id)
            ->whereIn('status', ['succeeded', 'partially_refunded'])
            ->orderByDesc('paid_at')
            ->get();

        foreach ($payments as $payment) {
            $paidForBooking = (int) ($payment->allocations[$booking->id] ?? 0);
            $alreadyRefunded = -1 * (int) LedgerEntry::where('payment_id', $payment->id)
                ->where('booking_id', $booking->id)->where('type', 'refund')->sum('amount_minor');
            $available = $paidForBooking - $alreadyRefunded;

            if ($available <= 0) {
                continue;
            }

            $amount = min($available, $remaining - $refunded);
            $this->gateway->refund($payment, $amount);

            DB::transaction(function () use ($booking, $payment, $amount, $memo) {
                LedgerEntry::record('refund', -$amount, $payment->currency, $booking->id, $booking->vendor_id, $payment->id, $memo);
                $booking->increment('refunded_minor', $amount);
                $payment->update(['status' => 'partially_refunded']);
            });

            $refunded += $amount;
            if ($refunded >= $remaining) {
                break;
            }
        }

        return $refunded;
    }
}

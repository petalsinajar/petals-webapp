<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Models\Booking;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Applies the cancellation tiers copied onto the booking when it was made.
 * Tiers: [{ days_before: 14, refund_percent: 100 }, { days_before: 3, refund_percent: 50 }]
 */
class RefundCalculator
{
    public function percentFor(Booking $booking, CarbonInterface $now): int
    {
        $daysBefore = (int) floor($now->diffInHours($booking->window_start, false) / 24);
        if ($daysBefore < 0) {
            return 0;
        }

        $tiers = collect($booking->policy_snapshot['cancellation'] ?? [])
            ->sortByDesc('days_before')
            ->values();

        foreach ($tiers as $tier) {
            if ($daysBefore >= (int) $tier['days_before']) {
                return (int) $tier['refund_percent'];
            }
        }

        return 0;
    }

    /** Amount to refund when the customer cancels. The damage deposit is always returned in full. */
    public function customerRefund(Booking $booking, CarbonInterface $now): int
    {
        $rentalPaid = max(0, $booking->paid_minor - $booking->damage_deposit_minor);
        $damagePaid = $booking->paid_minor - $rentalPaid;

        // The service fee is not refundable once the vendor has accepted.
        $refundableRental = max(0, $rentalPaid - $booking->service_fee_minor);

        return Money::percent($refundableRental, $this->percentFor($booking, $now)) + $damagePaid;
    }
}

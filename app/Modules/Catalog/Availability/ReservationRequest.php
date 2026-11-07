<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ReservationRequest
{
    public function __construct(
        public readonly string $vendorId,
        public readonly string $itemId,
        public readonly string $variantId,
        public readonly int $qty,
        /** Variant stock, used to create day counters the first time a day is touched. */
        public readonly int $capacity,
        /** Window including the vendor's buffer hours. */
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ReservationKind $kind,
        public readonly ?string $bookingId = null,
        public readonly ?string $reason = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {
        if ($qty < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if (! $end->greaterThan($start)) {
            throw new InvalidArgumentException('Reservation end must be after start.');
        }
        if ($kind === ReservationKind::Hold && ! $expiresAt) {
            throw new InvalidArgumentException('Holds need an expiry.');
        }
    }

    /** @return list<string> Y-m-d days touched by this window. */
    public function days(): array
    {
        return ReservationWindow::days($this->start, $this->end);
    }
}

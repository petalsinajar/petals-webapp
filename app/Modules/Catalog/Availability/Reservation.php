<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;

/** A row in the reservations ledger: who holds how much of a variant, and when. */
final class Reservation
{
    /**
     * @param  list<string>  $days
     */
    public function __construct(
        public readonly string $id,
        public readonly string $vendorId,
        public readonly string $itemId,
        public readonly string $variantId,
        public readonly int $qty,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $days,
        public readonly ReservationKind $kind,
        public readonly string $status,
        public readonly ?string $bookingId = null,
        public readonly ?string $reason = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendorId,
            'item_id' => $this->itemId,
            'variant_id' => $this->variantId,
            'qty' => $this->qty,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'days' => $this->days,
            'kind' => $this->kind->value,
            'status' => $this->status,
            'booking_id' => $this->bookingId,
            'reason' => $this->reason,
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }
}

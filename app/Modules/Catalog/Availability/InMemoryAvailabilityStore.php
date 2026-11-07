<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Same contract as MongoAvailabilityStore, kept in memory. Used by the test suite (and
 * handy for running the API without MongoDB). Not for production: state is per process.
 */
class InMemoryAvailabilityStore implements AvailabilityStore
{
    /** @var array<string, array{capacity: int, reserved: int}> keyed "variant|day" */
    private array $counters = [];

    /** @var array<string, Reservation> */
    private array $reservations = [];

    public function reserveMany(array $requests): array
    {
        // Work on copies so a failure leaves nothing behind (all-or-nothing).
        $counters = $this->counters;
        $created = [];

        foreach ($requests as $request) {
            foreach ($request->days() as $day) {
                $key = $request->variantId.'|'.$day;
                $counters[$key] ??= ['capacity' => $request->capacity, 'reserved' => 0];

                if ($counters[$key]['reserved'] + $request->qty > $counters[$key]['capacity']) {
                    throw new InsufficientStockException(
                        $request->variantId, $day, $request->qty,
                        max(0, $counters[$key]['capacity'] - $counters[$key]['reserved']),
                    );
                }
                $counters[$key]['reserved'] += $request->qty;
            }

            $created[] = new Reservation(
                id: Str::lower(bin2hex(random_bytes(12))),
                vendorId: $request->vendorId,
                itemId: $request->itemId,
                variantId: $request->variantId,
                qty: $request->qty,
                start: $request->start,
                end: $request->end,
                days: $request->days(),
                kind: $request->kind,
                status: 'active',
                bookingId: $request->bookingId,
                reason: $request->reason,
                expiresAt: $request->expiresAt,
            );
        }

        $this->counters = $counters;
        foreach ($created as $reservation) {
            $this->reservations[$reservation->id] = $reservation;
        }

        return $created;
    }

    public function release(string $reservationId): bool
    {
        $reservation = $this->reservations[$reservationId] ?? null;
        if (! $reservation || ! $reservation->isActive()) {
            return false;
        }

        foreach ($reservation->days as $day) {
            $this->counters[$reservation->variantId.'|'.$day]['reserved'] -= $reservation->qty;
        }
        $this->reservations[$reservationId] = $this->with($reservation, status: 'released');

        return true;
    }

    public function releaseForBooking(string $bookingId): int
    {
        $count = 0;
        foreach ($this->forBooking($bookingId) as $reservation) {
            $count += $this->release($reservation->id) ? 1 : 0;
        }

        return $count;
    }

    public function confirm(string $reservationId): bool
    {
        $reservation = $this->reservations[$reservationId] ?? null;
        if (! $reservation || ! $reservation->isActive() || $reservation->kind === ReservationKind::Block) {
            return false;
        }
        $this->reservations[$reservationId] = $this->with($reservation, kind: ReservationKind::Booking, clearExpiry: true);

        return true;
    }

    public function extendHold(string $reservationId, CarbonImmutable $expiresAt): bool
    {
        $reservation = $this->reservations[$reservationId] ?? null;
        if (! $reservation || ! $reservation->isActive() || $reservation->kind !== ReservationKind::Hold) {
            return false;
        }
        $this->reservations[$reservationId] = $this->with($reservation, expiresAt: $expiresAt);

        return true;
    }

    public function find(string $reservationId): ?Reservation
    {
        return $this->reservations[$reservationId] ?? null;
    }

    public function forBooking(string $bookingId): array
    {
        return array_values(array_filter($this->reservations, fn (Reservation $r) => $r->bookingId === $bookingId));
    }

    public function forVendor(string $vendorId, CarbonImmutable $from, CarbonImmutable $to, ?ReservationKind $kind = null): array
    {
        return array_values(array_filter($this->reservations, fn (Reservation $r) => $r->vendorId === $vendorId
            && $r->isActive()
            && $r->start->lessThan($to) && $r->end->greaterThan($from)
            && (! $kind || $r->kind === $kind)));
    }

    public function remaining(string $variantId, int $capacity, array $days): array
    {
        $out = [];
        foreach ($days as $day) {
            $counter = $this->counters[$variantId.'|'.$day] ?? null;
            $out[$day] = $counter ? max(0, $counter['capacity'] - $counter['reserved']) : $capacity;
        }

        return $out;
    }

    public function setCapacity(string $variantId, int $capacity, string $fromDay): array
    {
        $overbooked = [];
        foreach ($this->counters as $key => &$counter) {
            [$variant, $day] = explode('|', $key);
            if ($variant === $variantId && $day >= $fromDay) {
                $counter['capacity'] = $capacity;
                if ($counter['reserved'] > $capacity) {
                    $overbooked[] = $day;
                }
            }
        }
        sort($overbooked);

        return $overbooked;
    }

    public function expiredHolds(CarbonImmutable $now, int $limit = 200): array
    {
        $expired = array_filter($this->reservations, fn (Reservation $r) => $r->isActive()
            && $r->kind === ReservationKind::Hold
            && $r->expiresAt && $r->expiresAt->lessThanOrEqualTo($now));

        return array_slice(array_values($expired), 0, $limit);
    }

    private function with(
        Reservation $r,
        ?string $status = null,
        ?ReservationKind $kind = null,
        ?CarbonImmutable $expiresAt = null,
        bool $clearExpiry = false,
    ): Reservation {
        return new Reservation(
            $r->id, $r->vendorId, $r->itemId, $r->variantId, $r->qty, $r->start, $r->end, $r->days,
            $kind ?? $r->kind, $status ?? $r->status, $r->bookingId, $r->reason,
            $clearExpiry ? null : ($expiresAt ?? $r->expiresAt),
        );
    }
}

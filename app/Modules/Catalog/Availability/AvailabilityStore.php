<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;

/**
 * The stock ledger. Reservations and the per-day counters they update live in the main
 * database (SqlAvailabilityStore), so checking and reserving stock is one atomic step and can
 * share a transaction with the bookings that need it.
 *
 * Every write is idempotent: releasing an already released reservation, or confirming an
 * already confirmed one, changes nothing.
 */
interface AvailabilityStore
{
    /**
     * Reserve all requests or none of them, in one transaction.
     *
     * @param  list<ReservationRequest>  $requests
     * @return list<Reservation>
     *
     * @throws InsufficientStockException
     */
    public function reserveMany(array $requests): array;

    /** Give stock back. Returns false when it was already released. */
    public function release(string $reservationId): bool;

    /**
     * Release every active reservation for a booking.
     *
     * @return int number released
     */
    public function releaseForBooking(string $bookingId): int;

    /** Turn a hold into a paid booking (no stock change). False if the hold is gone. */
    public function confirm(string $reservationId): bool;

    /** Move a hold's expiry, e.g. when a vendor accepts and the deposit clock starts. */
    public function extendHold(string $reservationId, CarbonImmutable $expiresAt): bool;

    public function find(string $reservationId): ?Reservation;

    /** @return list<Reservation> */
    public function forBooking(string $bookingId): array;

    /**
     * Active reservations for a vendor overlapping [from, to).
     *
     * @return list<Reservation>
     */
    public function forVendor(string $vendorId, CarbonImmutable $from, CarbonImmutable $to, ?ReservationKind $kind = null): array;

    /**
     * Remaining quantity per day. Days with no counter are fully available.
     *
     * @param  list<string>  $days
     * @return array<string, int>
     */
    public function remaining(string $variantId, int $capacity, array $days): array;

    /**
     * Change a variant's stock from a day onward.
     *
     * @return list<string> days where existing reservations now exceed capacity
     */
    public function setCapacity(string $variantId, int $capacity, string $fromDay): array;

    /** @return list<Reservation> */
    public function expiredHolds(CarbonImmutable $now, int $limit = 200): array;
}

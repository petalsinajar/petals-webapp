<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgument;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use MongoDB\Laravel\Connection;

use function MongoDB\with_transaction;

/**
 * Production stock ledger on MongoDB.
 *
 * Collections:
 *   availability_days  { variant_id, day: "2026-12-05", capacity, reserved }   unique (variant_id, day)
 *   reservations       { vendor_id, item_id, variant_id, qty, start, end, days[], kind, status,
 *                        booking_id, reason, expires_at, created_at, updated_at }
 *
 * Reserving runs in a multi-document transaction (MongoDB must be a replica set; Atlas always is):
 *   1. upsert the day counter if missing ($setOnInsert capacity, reserved: 0)
 *   2. conditionally $inc reserved only where reserved + qty <= capacity
 *   3. if any day matched nothing, abort: that day ran out
 *   4. insert the reservation
 */
class MongoAvailabilityStore implements AvailabilityStore
{
    public function __construct(private readonly string $connection = 'mongodb') {}

    public function reserveMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->transaction(function (Session $session) use ($requests): array {
            $now = new UTCDateTime;
            $reservations = [];

            foreach ($requests as $request) {
                foreach ($request->days() as $day) {
                    $this->incrementDay($session, $request, $day);
                }

                $document = [
                    'vendor_id' => $request->vendorId,
                    'item_id' => $request->itemId,
                    'variant_id' => $request->variantId,
                    'qty' => $request->qty,
                    'start' => $this->utc($request->start),
                    'end' => $this->utc($request->end),
                    'days' => $request->days(),
                    'kind' => $request->kind->value,
                    'status' => 'active',
                    'booking_id' => $request->bookingId,
                    'reason' => $request->reason,
                    'expires_at' => $request->expiresAt ? $this->utc($request->expiresAt) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $result = $this->reservations()->insertOne($document, ['session' => $session]);
                $document['_id'] = $result->getInsertedId();
                $reservations[] = $this->hydrate($document);
            }

            return $reservations;
        });
    }

    public function release(string $reservationId): bool
    {
        $id = $this->objectId($reservationId);
        if (! $id) {
            return false;
        }

        return $this->transaction(fn (Session $session) => $this->releaseInSession($session, ['_id' => $id]) > 0);
    }

    public function releaseForBooking(string $bookingId): int
    {
        return $this->transaction(fn (Session $session) => $this->releaseInSession($session, ['booking_id' => $bookingId]));
    }

    public function confirm(string $reservationId): bool
    {
        $id = $this->objectId($reservationId);
        if (! $id) {
            return false;
        }

        $result = $this->reservations()->updateOne(
            ['_id' => $id, 'status' => 'active', 'kind' => ['$in' => [ReservationKind::Hold->value, ReservationKind::Booking->value]]],
            ['$set' => ['kind' => ReservationKind::Booking->value, 'expires_at' => null, 'updated_at' => new UTCDateTime]],
        );

        return $result->getMatchedCount() > 0;
    }

    public function extendHold(string $reservationId, CarbonImmutable $expiresAt): bool
    {
        $id = $this->objectId($reservationId);
        if (! $id) {
            return false;
        }

        $result = $this->reservations()->updateOne(
            ['_id' => $id, 'status' => 'active', 'kind' => ReservationKind::Hold->value],
            ['$set' => ['expires_at' => $this->utc($expiresAt), 'updated_at' => new UTCDateTime]],
        );

        return $result->getMatchedCount() > 0;
    }

    public function find(string $reservationId): ?Reservation
    {
        $id = $this->objectId($reservationId);
        $document = $id ? $this->reservations()->findOne(['_id' => $id]) : null;

        return $document ? $this->hydrate($this->toArray($document)) : null;
    }

    public function forBooking(string $bookingId): array
    {
        return $this->hydrateAll($this->reservations()->find(['booking_id' => $bookingId]));
    }

    public function forVendor(string $vendorId, CarbonImmutable $from, CarbonImmutable $to, ?ReservationKind $kind = null): array
    {
        $filter = [
            'vendor_id' => $vendorId,
            'status' => 'active',
            'start' => ['$lt' => $this->utc($to)],
            'end' => ['$gt' => $this->utc($from)],
        ];
        if ($kind) {
            $filter['kind'] = $kind->value;
        }

        return $this->hydrateAll($this->reservations()->find($filter, ['sort' => ['start' => 1], 'limit' => 1000]));
    }

    public function remaining(string $variantId, int $capacity, array $days): array
    {
        $remaining = array_fill_keys($days, $capacity);

        $counters = $this->days()->find(['variant_id' => $variantId, 'day' => ['$in' => array_values($days)]]);
        foreach ($counters as $counter) {
            $remaining[$counter['day']] = max(0, (int) $counter['capacity'] - (int) $counter['reserved']);
        }

        return $remaining;
    }

    public function setCapacity(string $variantId, int $capacity, string $fromDay): array
    {
        $this->days()->updateMany(
            ['variant_id' => $variantId, 'day' => ['$gte' => $fromDay]],
            ['$set' => ['capacity' => $capacity]],
        );

        $overbooked = $this->days()->find([
            'variant_id' => $variantId,
            'day' => ['$gte' => $fromDay],
            '$expr' => ['$gt' => ['$reserved', '$capacity']],
        ], ['projection' => ['day' => 1], 'sort' => ['day' => 1]]);

        return array_map(fn ($d) => $d['day'], iterator_to_array($overbooked, false));
    }

    public function expiredHolds(CarbonImmutable $now, int $limit = 200): array
    {
        return $this->hydrateAll($this->reservations()->find([
            'kind' => ReservationKind::Hold->value,
            'status' => 'active',
            'expires_at' => ['$lte' => $this->utc($now)],
        ], ['limit' => $limit]));
    }

    // ---------------------------------------------------------------------

    private function incrementDay(Session $session, ReservationRequest $request, string $day): void
    {
        $key = ['variant_id' => $request->variantId, 'day' => $day];

        $this->days()->updateOne(
            $key,
            ['$setOnInsert' => ['capacity' => $request->capacity, 'reserved' => 0]],
            ['upsert' => true, 'session' => $session],
        );

        $result = $this->days()->updateOne(
            [...$key, '$expr' => ['$lte' => [['$add' => ['$reserved', $request->qty]], '$capacity']]],
            ['$inc' => ['reserved' => $request->qty]],
            ['session' => $session],
        );

        if ($result->getModifiedCount() === 0) {
            $counter = $this->days()->findOne($key, ['session' => $session]);
            $available = $counter ? max(0, (int) $counter['capacity'] - (int) $counter['reserved']) : 0;

            throw new InsufficientStockException($request->variantId, $day, $request->qty, $available);
        }
    }

    /** Release matching active reservations and decrement their day counters. */
    private function releaseInSession(Session $session, array $filter): int
    {
        $released = 0;
        $active = $this->reservations()->find([...$filter, 'status' => 'active'], ['session' => $session]);

        foreach ($active as $reservation) {
            // Conditional on status so a concurrent release can't double-decrement.
            $result = $this->reservations()->updateOne(
                ['_id' => $reservation['_id'], 'status' => 'active'],
                ['$set' => ['status' => 'released', 'updated_at' => new UTCDateTime]],
                ['session' => $session],
            );
            if ($result->getModifiedCount() === 0) {
                continue;
            }

            $this->days()->updateMany(
                ['variant_id' => $reservation['variant_id'], 'day' => ['$in' => iterator_to_array($reservation['days'], false)]],
                ['$inc' => ['reserved' => -1 * (int) $reservation['qty']]],
                ['session' => $session],
            );
            $released++;
        }

        return $released;
    }

    /**
     * Runs the callback in a transaction, retrying on transient errors. Any other exception
     * aborts the transaction and is re-thrown.
     *
     * @template T
     *
     * @param  Closure(Session): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        $session = $this->connection()->getClient()->startSession();
        $result = null;

        try {
            with_transaction($session, function (Session $session) use ($callback, &$result) {
                $result = $callback($session);
            }, ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)]);
        } finally {
            $session->endSession();
        }

        return $result;
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection($this->connection);
    }

    private function days(): Collection
    {
        return $this->connection()->getCollection('availability_days');
    }

    private function reservations(): Collection
    {
        return $this->connection()->getCollection('reservations');
    }

    private function objectId(string $id): ?ObjectId
    {
        try {
            return new ObjectId($id);
        } catch (DriverInvalidArgument) {
            return null;
        }
    }

    private function utc(DateTimeInterface $date): UTCDateTime
    {
        return new UTCDateTime($date);
    }

    /** @return list<Reservation> */
    private function hydrateAll(iterable $cursor): array
    {
        $out = [];
        foreach ($cursor as $document) {
            $out[] = $this->hydrate($this->toArray($document));
        }

        return $out;
    }

    private function toArray(object|array $document): array
    {
        return $document instanceof \ArrayObject ? $document->getArrayCopy() : (array) $document;
    }

    private function hydrate(array $d): Reservation
    {
        $date = fn ($v) => $v instanceof UTCDateTime ? CarbonImmutable::instance($v->toDateTime()) : null;
        $days = $d['days'] ?? [];

        return new Reservation(
            id: (string) $d['_id'],
            vendorId: (string) $d['vendor_id'],
            itemId: (string) $d['item_id'],
            variantId: (string) $d['variant_id'],
            qty: (int) $d['qty'],
            start: $date($d['start']),
            end: $date($d['end']),
            days: is_array($days) ? array_values($days) : iterator_to_array($days, false),
            kind: ReservationKind::from($d['kind']),
            status: (string) $d['status'],
            bookingId: $d['booking_id'] ?? null,
            reason: $d['reason'] ?? null,
            expiresAt: $date($d['expires_at'] ?? null),
        );
    }
}

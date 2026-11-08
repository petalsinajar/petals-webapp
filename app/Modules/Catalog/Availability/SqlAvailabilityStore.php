<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The stock ledger, in the same database as bookings.
 *
 *   availability_days  (variant_id, day) primary key, capacity, reserved
 *   reservations       vendor/item/variant, qty, starts_at, ends_at, days[], kind, status, booking_id ...
 *
 * Reserving, per (variant, day), always in (variant_id, day) order so two checkouts lock rows
 * in the same order and can't deadlock:
 *   1. INSERT the day counter if it's missing (capacity from the variant, reserved 0)
 *   2. UPDATE availability_days SET reserved = reserved + qty WHERE ... AND reserved + qty <= capacity
 *   3. zero rows updated means that day ran out: throw, and the transaction rolls back
 *
 * The guarded UPDATE takes a row lock, so a concurrent checkout waits and then re-checks the
 * condition against the committed value. When the caller already has a transaction open
 * (CheckoutService), this runs inside it and stock, checkout and bookings commit together.
 */
class SqlAvailabilityStore implements AvailabilityStore
{
    public function __construct(private readonly ?string $connection = null) {}

    public function reserveMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->db()->transaction(function () use ($requests): array {
            // Several lines can share a variant (or a package can repeat one): sum them per day.
            $touches = [];
            foreach ($requests as $request) {
                foreach ($request->days() as $day) {
                    $key = $request->variantId.'|'.$day;
                    $touches[$key] ??= ['variant_id' => $request->variantId, 'day' => $day, 'qty' => 0, 'capacity' => $request->capacity];
                    $touches[$key]['qty'] += $request->qty;
                }
            }
            ksort($touches, SORT_STRING);

            foreach ($touches as $touch) {
                $this->takeDay($touch['variant_id'], $touch['day'], $touch['qty'], $touch['capacity']);
            }

            $now = $this->ts(CarbonImmutable::now());
            $reservations = [];
            $rows = [];

            foreach ($requests as $request) {
                $reservation = new Reservation(
                    id: strtolower((string) Str::ulid()),
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

                $rows[] = [
                    'id' => $reservation->id,
                    'vendor_id' => $reservation->vendorId,
                    'item_id' => $reservation->itemId,
                    'variant_id' => $reservation->variantId,
                    'qty' => $reservation->qty,
                    'starts_at' => $this->ts($reservation->start),
                    'ends_at' => $this->ts($reservation->end),
                    'days' => json_encode($reservation->days),
                    'kind' => $reservation->kind->value,
                    'status' => 'active',
                    'booking_id' => $reservation->bookingId,
                    'reason' => $reservation->reason,
                    'expires_at' => $reservation->expiresAt ? $this->ts($reservation->expiresAt) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $reservations[] = $reservation;
            }

            $this->reservations()->insert($rows);

            return $reservations;
        });
    }

    public function release(string $reservationId): bool
    {
        return $this->releaseWhere(fn (Builder $q) => $q->where('id', $reservationId)) > 0;
    }

    public function releaseForBooking(string $bookingId): int
    {
        return $this->releaseWhere(fn (Builder $q) => $q->where('booking_id', $bookingId));
    }

    public function confirm(string $reservationId): bool
    {
        $this->reservations()
            ->where('id', $reservationId)->where('status', 'active')
            ->whereIn('kind', [ReservationKind::Hold->value, ReservationKind::Booking->value])
            ->update(['kind' => ReservationKind::Booking->value, 'expires_at' => null, 'updated_at' => $this->ts(CarbonImmutable::now())]);

        // Checked rather than counted: MySQL reports 0 affected rows when nothing changed,
        // and confirming an already confirmed booking must still say yes.
        return $this->reservations()
            ->where('id', $reservationId)->where('status', 'active')
            ->where('kind', ReservationKind::Booking->value)
            ->exists();
    }

    public function extendHold(string $reservationId, CarbonImmutable $expiresAt): bool
    {
        $query = fn () => $this->reservations()
            ->where('id', $reservationId)->where('status', 'active')
            ->where('kind', ReservationKind::Hold->value);

        $query()->update(['expires_at' => $this->ts($expiresAt), 'updated_at' => $this->ts(CarbonImmutable::now())]);

        return $query()->exists();
    }

    public function find(string $reservationId): ?Reservation
    {
        if (! Str::isUlid($reservationId)) {
            return null;
        }

        $row = $this->reservations()->where('id', strtolower($reservationId))->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function forBooking(string $bookingId): array
    {
        return $this->reservations()->where('booking_id', $bookingId)->orderBy('id')->get()
            ->map(fn (object $row) => $this->hydrate($row))->all();
    }

    public function forVendor(string $vendorId, CarbonImmutable $from, CarbonImmutable $to, ?ReservationKind $kind = null): array
    {
        return $this->reservations()
            ->where('vendor_id', $vendorId)
            ->where('status', 'active')
            ->where('starts_at', '<', $this->ts($to))
            ->where('ends_at', '>', $this->ts($from))
            ->when($kind, fn (Builder $q) => $q->where('kind', $kind->value))
            ->orderBy('starts_at')
            ->limit(1000)
            ->get()
            ->map(fn (object $row) => $this->hydrate($row))->all();
    }

    public function remaining(string $variantId, int $capacity, array $days): array
    {
        $remaining = array_fill_keys($days, $capacity);

        $counters = $this->days()->where('variant_id', $variantId)->whereIn('day', array_values($days))->get();
        foreach ($counters as $counter) {
            $remaining[$this->dayString($counter->day)] = max(0, (int) $counter->capacity - (int) $counter->reserved);
        }

        return $remaining;
    }

    public function setCapacity(string $variantId, int $capacity, string $fromDay): array
    {
        $this->days()->where('variant_id', $variantId)->where('day', '>=', $fromDay)->update(['capacity' => $capacity]);

        return $this->days()
            ->where('variant_id', $variantId)->where('day', '>=', $fromDay)
            ->whereColumn('reserved', '>', 'capacity')
            ->orderBy('day')
            ->pluck('day')
            ->map(fn ($day) => $this->dayString($day))
            ->all();
    }

    public function expiredHolds(CarbonImmutable $now, int $limit = 200): array
    {
        return $this->reservations()
            ->where('kind', ReservationKind::Hold->value)
            ->where('status', 'active')
            ->where('expires_at', '<=', $this->ts($now))
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->hydrate($row))->all();
    }

    // ---------------------------------------------------------------------

    private function takeDay(string $variantId, string $day, int $qty, int $capacity): void
    {
        $this->days()->insertOrIgnore(['variant_id' => $variantId, 'day' => $day, 'capacity' => $capacity, 'reserved' => 0]);

        $updated = $this->days()
            ->where('variant_id', $variantId)->where('day', $day)
            ->whereRaw('reserved + ? <= capacity', [$qty])
            ->increment('reserved', $qty);

        if ($updated === 0) {
            $counter = $this->days()->where('variant_id', $variantId)->where('day', $day)->first();
            $available = $counter ? max(0, (int) $counter->capacity - (int) $counter->reserved) : 0;

            throw new InsufficientStockException($variantId, $day, $qty, $available);
        }
    }

    /**
     * Release matching active reservations and give their days back. Each reservation flips
     * to released with a status-conditional UPDATE, so a concurrent release can't
     * double-decrement; counters are then touched in (variant_id, day) order.
     *
     * @param  \Closure(Builder): Builder  $filter
     */
    private function releaseWhere(\Closure $filter): int
    {
        return $this->db()->transaction(function () use ($filter): int {
            $active = $filter($this->reservations())->where('status', 'active')->orderBy('id')->get(['id', 'variant_id', 'qty', 'days']);
            $now = $this->ts(CarbonImmutable::now());
            $touches = [];
            $released = 0;

            foreach ($active as $row) {
                $flipped = $this->reservations()->where('id', $row->id)->where('status', 'active')
                    ->update(['status' => 'released', 'updated_at' => $now]);
                if ($flipped === 0) {
                    continue;
                }

                foreach (json_decode($row->days, true) as $day) {
                    $key = $row->variant_id.'|'.$day;
                    $touches[$key] = ($touches[$key] ?? 0) + (int) $row->qty;
                }
                $released++;
            }

            ksort($touches, SORT_STRING);
            foreach ($touches as $key => $qty) {
                [$variantId, $day] = explode('|', $key);
                $this->days()->where('variant_id', $variantId)->where('day', $day)->decrement('reserved', $qty);
            }

            return $released;
        });
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    private function days(): Builder
    {
        return $this->db()->table('availability_days');
    }

    private function reservations(): Builder
    {
        return $this->db()->table('reservations');
    }

    /** Timestamps are stored in the app timezone (UTC), like Eloquent does. */
    private function ts(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'));
    }

    private function dayString(mixed $day): string
    {
        return substr((string) $day, 0, 10);
    }

    private function hydrate(object $row): Reservation
    {
        return new Reservation(
            id: $row->id,
            vendorId: $row->vendor_id,
            itemId: $row->item_id,
            variantId: $row->variant_id,
            qty: (int) $row->qty,
            start: $this->date($row->starts_at),
            end: $this->date($row->ends_at),
            days: json_decode($row->days, true),
            kind: ReservationKind::from($row->kind),
            status: $row->status,
            bookingId: $row->booking_id,
            reason: $row->reason,
            expiresAt: $this->date($row->expires_at),
        );
    }
}

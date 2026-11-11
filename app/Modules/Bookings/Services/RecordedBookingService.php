<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusChanged;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\InsufficientStockException;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A booking the vendor agreed outside the app (phone, walk-in), recorded so its stock is
 * kept off the calendar. It starts confirmed, with no app payment and no commission. When the
 * phone belongs to an app user, the booking is theirs too and they hear about it.
 */
class RecordedBookingService
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly AvailabilityStore $store,
    ) {}

    /**
     * @param  array{customer: array{name: string, phone: string}, deliver_at: string, collect_at: string, fulfilment: array{type: string, address?: ?string}, lines: list<array{item_id: string, variant_id: string, qty: int}>, delivery_minor?: ?int, paid_minor?: ?int, note?: ?string}  $input
     */
    public function record(Vendor $vendor, User $actor, array $input): Booking
    {
        $phone = PhoneNumber::normalize($input['customer']['phone'])
            ?? throw ValidationException::withMessages(['customer.phone' => 'Enter a valid phone number.']);
        $deliverAt = CarbonImmutable::parse($input['deliver_at']);
        $collectAt = CarbonImmutable::parse($input['collect_at']);

        if (! $collectAt->greaterThan($deliverAt)) {
            throw ValidationException::withMessages(['collect_at' => 'The return must be after the items go out.']);
        }
        if ($collectAt->isPast()) {
            throw ValidationException::withMessages(['collect_at' => 'This booking has already ended.']);
        }

        $bookingId = strtolower((string) Str::ulid());
        [$reserveFrom, $reserveTo] = ReservationWindow::withBuffer($deliverAt, $collectAt, (int) $vendor->policy('buffer_hours'));

        $lines = [];
        $requests = [];
        $titles = [];
        $subtotal = 0;
        $currency = null;

        foreach ($input['lines'] as $index => $line) {
            $s = $this->catalog->variant($line['item_id'], $line['variant_id'], (int) $line['qty']);
            if (! $s || $s->vendorId !== $vendor->id) {
                throw ValidationException::withMessages(["lines.$index" => 'This item is not in your inventory.']);
            }
            if ($currency !== null && $s->currency !== $currency) {
                throw ValidationException::withMessages(['lines' => 'All items must use the same currency.']);
            }
            $currency = $s->currency;
            $qty = (int) $line['qty'];
            $subtotal += $s->unitPriceMinor * $qty;
            $titles[$s->variantId] = [$index, $s->title];

            $lines[] = [
                'item_id' => $s->itemId,
                'variant_id' => $s->variantId,
                'title_snapshot' => Str::limit($s->title, 200, ''),
                'qty' => $qty,
                'unit_price_minor' => $s->unitPriceMinor,
                'line_total_minor' => $s->unitPriceMinor * $qty,
            ];
            $requests[] = new ReservationRequest(
                vendorId: $vendor->id,
                itemId: $s->itemId,
                variantId: $s->variantId,
                qty: $qty,
                capacity: $s->quantity,
                start: $reserveFrom,
                end: $reserveTo,
                kind: ReservationKind::Booking,
                bookingId: $bookingId,
            );
        }

        $delivery = $input['fulfilment']['type'] === 'delivery' ? (int) ($input['delivery_minor'] ?? 0) : 0;
        $total = $subtotal + $delivery;
        $customer = User::where('phone', $phone)->first();

        try {
            $booking = DB::transaction(function () use ($vendor, $actor, $input, $bookingId, $requests, $lines, $customer, $phone, $deliverAt, $collectAt, $subtotal, $delivery, $total, $currency) {
                $reservations = $this->store->reserveMany($requests);

                $booking = new Booking([
                    'vendor_id' => $vendor->id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $input['customer']['name'],
                    'customer_phone' => $phone,
                    'source' => 'vendor_manual',
                    'window_start' => $deliverAt,
                    'window_end' => $collectAt,
                    'fulfilment' => [
                        'type' => $input['fulfilment']['type'],
                        'address' => $input['fulfilment']['address'] ?? null,
                        'lat' => null,
                        'lng' => null,
                        'setup' => false,
                        'deliver_at' => $deliverAt->toIso8601String(),
                        'collect_at' => $collectAt->toIso8601String(),
                        'contact_phone' => $phone,
                    ],
                    'subtotal_minor' => $subtotal,
                    'delivery_minor' => $delivery,
                    'total_minor' => $total,
                    'deposit_due_minor' => 0,
                    'paid_minor' => min($total, (int) ($input['paid_minor'] ?? 0)),
                    'currency' => $currency,
                    'commission_percent' => 0,
                    'commission_minor' => 0,
                    'policy_snapshot' => [
                        'cancellation' => $vendor->policy('cancellation'),
                        'buffer_hours' => (int) $vendor->policy('buffer_hours'),
                    ],
                    'status' => BookingStatus::Confirmed,
                    'notes' => ($input['note'] ?? null) ? ['vendor_internal' => $input['note']] : null,
                ]);
                $booking->id = $bookingId;
                $booking->number = Booking::nextNumber();
                $booking->save();

                foreach ($lines as $i => $line) {
                    $booking->lines()->create([...$line, 'reservation_id' => $reservations[$i]->id]);
                }

                $booking->statusEvents()->create([
                    'from_status' => null,
                    'to_status' => BookingStatus::Confirmed->value,
                    'actor_id' => $actor->id,
                    'note' => 'Recorded by the vendor',
                    'created_at' => now(),
                ]);

                return $booking;
            });
        } catch (InsufficientStockException $e) {
            [$index, $title] = $titles[$e->variantId] ?? [0, 'This item'];
            $day = CarbonImmutable::parse($e->day)->format('j M');

            throw ValidationException::withMessages([
                "lines.$index.qty" => "Only {$e->available} {$title} free on {$day}.",
            ]);
        }

        $booking->load('vendor');
        $booking->customer?->notify(new BookingStatusChanged($booking));

        return $booking;
    }
}

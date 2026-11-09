<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Events\BookingCreated;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\Checkout;
use App\Modules\Bookings\Notifications\BookingRequested;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Support\Geo;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a cart into bookings, one per vendor, in ONE database transaction:
 *
 *   1. Build every booking in memory (prices, fees, the stock it needs).
 *   2. Reserve all stock as holds carrying the booking ids (all-or-nothing across vendors).
 *   3. Write the checkout, bookings and lines.
 *
 * Out of stock, or any failure while writing, rolls the whole thing back: no stray holds,
 * no half-written checkout. Notifications go out after commit.
 */
class CheckoutService
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly AvailabilityStore $store,
    ) {}

    /**
     * @param  array{event_id: string, lines: list<array{item_id: string, variant_id: string, qty: int}>, fulfilment?: array<string, array<string, mixed>>, notes?: array<string, string>}  $input
     */
    public function create(User $customer, Event $event, array $input): Checkout
    {
        if ($event->ends_at->isPast()) {
            throw ValidationException::withMessages(['event_id' => 'This event has already happened.']);
        }

        $drafts = $this->buildDrafts($event, $input);
        $currency = $this->singleCurrency($drafts);

        $checkout = DB::transaction(function () use ($customer, $event, $drafts, $currency) {
            $reservations = $this->store->reserveMany(collect($drafts)->flatMap(fn (array $d) => $d['requests'])->all());

            $checkout = Checkout::create([
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'currency' => $currency,
                'total_minor' => array_sum(array_column($drafts, 'grand_total')),
                'status' => 'open',
            ]);

            $i = 0;
            foreach ($drafts as $draft) {
                /** @var Booking $booking */
                $booking = new Booking($draft['attributes']);
                $booking->id = $draft['id'];
                $booking->checkout_id = $checkout->id;
                $booking->customer_id = $customer->id;
                $booking->number = $this->nextNumber();
                $booking->save();

                foreach ($draft['lines'] as $line) {
                    $booking->lines()->create([...$line, 'reservation_id' => $reservations[$i++]->id]);
                }

                $booking->statusEvents()->create([
                    'from_status' => null,
                    'to_status' => $booking->status->value,
                    'actor_id' => $customer->id,
                    'note' => 'Requested',
                    'created_at' => now(),
                ]);
            }

            $event->increment('bookings_count', count($drafts));

            return $checkout;
        });

        $checkout->load('bookings.lines', 'bookings.vendor', 'bookings.customer');

        foreach ($checkout->bookings as $booking) {
            if ($booking->status === BookingStatus::PendingVendor) {
                Notification::send($booking->vendor->managers()->get(), new BookingRequested($booking));
            }
            BookingCreated::dispatch($booking->id, $booking->event_id, $booking->vendor_id);
        }

        return $checkout;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDrafts(Event $event, array $input): array
    {
        // Snapshot every line and group by vendor.
        $byVendor = [];
        foreach ($input['lines'] as $index => $line) {
            $snapshot = $this->catalog->variant($line['item_id'], $line['variant_id'], (int) $line['qty']);

            if (! $snapshot || ! $snapshot->active) {
                throw ValidationException::withMessages(["lines.$index" => 'This item is no longer available.']);
            }
            if ($line['qty'] < $snapshot->minQty) {
                throw ValidationException::withMessages(["lines.$index.qty" => "Minimum order is {$snapshot->minQty}."]);
            }
            $byVendor[$snapshot->vendorId][] = ['snapshot' => $snapshot, 'qty' => (int) $line['qty']];
        }

        $vendors = Vendor::whereIn('id', array_keys($byVendor))->where('status', 'active')->get()->keyBy('id');
        $drafts = [];

        foreach ($byVendor as $vendorId => $lines) {
            $vendor = $vendors->get($vendorId) ?? throw ValidationException::withMessages(['lines' => 'A vendor in your cart is not taking bookings.']);
            $fulfilment = $this->fulfilment($event, $vendor, $input['fulfilment'][$vendorId] ?? []);
            $drafts[] = $this->draft($event, $vendor, $lines, $fulfilment, $input['notes'][$vendorId] ?? null);
        }

        return $drafts;
    }

    /**
     * @param  list<array{snapshot: VariantSnapshot, qty: int}>  $lines
     * @param  array<string, mixed>  $fulfilment
     * @return array<string, mixed>
     */
    private function draft(Event $event, Vendor $vendor, array $lines, array $fulfilment, ?string $note): array
    {
        $bookingId = strtolower((string) Str::ulid());
        $deliverAt = CarbonImmutable::parse($fulfilment['deliver_at']);
        $collectAt = CarbonImmutable::parse($fulfilment['collect_at']);

        $leadHours = (int) $vendor->policy('lead_time_hours');
        if ($deliverAt->lessThan(now()->addHours($leadHours))) {
            throw ValidationException::withMessages(["fulfilment.{$vendor->id}.deliver_at" => "{$vendor->name} needs at least {$leadHours} hours' notice."]);
        }

        // Without in-app payment there's no deposit to secure an instant booking, so every
        // booking is a request the vendor answers.
        $instant = config('eventapp.payments.in_app')
            && collect($lines)->every(fn ($l) => $l['snapshot']->instantBook);
        $status = $instant ? BookingStatus::Accepted : BookingStatus::PendingVendor;
        $holdUntil = $instant
            ? now()->addMinutes(config('eventapp.bookings.hold_minutes'))->toImmutable()
            : now()->addHours(config('eventapp.bookings.vendor_response_hours'))->toImmutable();

        [$reserveFrom, $reserveTo] = ReservationWindow::withBuffer($deliverAt, $collectAt, (int) $vendor->policy('buffer_hours'));

        $bookingLines = [];
        $requests = [];
        $subtotal = 0;
        $damage = 0;
        $needsSetup = false;

        foreach ($lines as ['snapshot' => $s, 'qty' => $qty]) {
            $lineTotal = $s->unitPriceMinor * $qty;
            $subtotal += $lineTotal;
            $damage += $s->damageDepositMinor * $qty;
            $needsSetup = $needsSetup || $s->requiresSetup;

            $bookingLines[] = [
                'item_id' => $s->itemId,
                'variant_id' => $s->variantId,
                'title_snapshot' => Str::limit($s->title, 200, ''),
                'qty' => $qty,
                'unit_price_minor' => $s->unitPriceMinor,
                'line_total_minor' => $lineTotal,
            ];

            $requests[] = new ReservationRequest(
                vendorId: $vendor->id,
                itemId: $s->itemId,
                variantId: $s->variantId,
                qty: $qty,
                capacity: $s->quantity,
                start: $reserveFrom,
                end: $reserveTo,
                kind: ReservationKind::Hold,
                bookingId: $bookingId,
                expiresAt: $holdUntil,
            );
        }

        $delivery = $this->deliveryFee($vendor, $event, $fulfilment);
        $setup = ($fulfilment['setup'] ?? false) || $needsSetup
            ? (int) Arr::get($vendor->delivery ?? [], 'setup_fee_minor', 0)
            : 0;
        $serviceFee = Money::percent($subtotal, (float) config('eventapp.bookings.service_fee_percent'));
        $total = $subtotal + $delivery + $setup + $serviceFee;
        $commissionPercent = (float) config('eventapp.bookings.commission_percent');
        $depositPercent = (int) $vendor->policy('deposit_percent');

        return [
            'id' => $bookingId,
            'lines' => $bookingLines,
            'requests' => $requests,
            'grand_total' => $total + $damage,
            'attributes' => [
                'event_id' => $event->id,
                'vendor_id' => $vendor->id,
                'source' => 'app',
                'window_start' => $deliverAt,
                'window_end' => $collectAt,
                'fulfilment' => $fulfilment,
                'subtotal_minor' => $subtotal,
                'delivery_minor' => $delivery,
                'setup_minor' => $setup,
                'discount_minor' => 0,
                'service_fee_minor' => $serviceFee,
                'total_minor' => $total,
                'deposit_due_minor' => Money::percent($total, $depositPercent),
                'damage_deposit_minor' => $damage,
                'paid_minor' => 0,
                'currency' => $lines[0]['snapshot']->currency,
                'commission_percent' => $commissionPercent,
                'commission_minor' => Money::percent($subtotal, $commissionPercent),
                'policy_snapshot' => [
                    'deposit_percent' => $depositPercent,
                    'cancellation' => $vendor->policy('cancellation'),
                    'buffer_hours' => (int) $vendor->policy('buffer_hours'),
                ],
                'status' => $status,
                'vendor_respond_by' => $instant ? null : $holdUntil,
                'pay_by' => $instant ? $holdUntil : null,
                'notes' => $note ? ['customer' => $note] : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fulfilment(Event $event, Vendor $vendor, array $input): array
    {
        $type = $input['type'] ?? 'delivery';
        if ($type === 'delivery' && ! Arr::get($vendor->delivery ?? [], 'offered', true)) {
            throw ValidationException::withMessages(["fulfilment.{$vendor->id}.type" => "{$vendor->name} doesn't deliver. Choose pickup."]);
        }

        $deliverAt = isset($input['deliver_at']) ? CarbonImmutable::parse($input['deliver_at']) : $event->starts_at->subHours(3);
        $collectAt = isset($input['collect_at']) ? CarbonImmutable::parse($input['collect_at']) : $event->ends_at->addHours(3);

        if (! $collectAt->greaterThan($deliverAt)) {
            throw ValidationException::withMessages(["fulfilment.{$vendor->id}.collect_at" => 'Collection must be after delivery.']);
        }

        return [
            'type' => $type,
            'address' => $input['address'] ?? $event->venue_address,
            'lat' => $input['lat'] ?? $event->venue_lat,
            'lng' => $input['lng'] ?? $event->venue_lng,
            'setup' => (bool) ($input['setup'] ?? false),
            'deliver_at' => $deliverAt->toIso8601String(),
            'collect_at' => $collectAt->toIso8601String(),
            'contact_phone' => $input['contact_phone'] ?? null,
        ];
    }

    private function deliveryFee(Vendor $vendor, Event $event, array $fulfilment): int
    {
        if ($fulfilment['type'] !== 'delivery') {
            return 0;
        }

        $fee = (int) Arr::get($vendor->delivery ?? [], 'base_fee_minor', 0);
        $perKm = (int) Arr::get($vendor->delivery ?? [], 'per_km_minor', 0);

        if ($perKm > 0 && $vendor->base_lat !== null && $fulfilment['lat'] !== null) {
            $fee += (int) ceil(Geo::distanceKm($vendor->base_lat, $vendor->base_lng, (float) $fulfilment['lat'], (float) $fulfilment['lng'])) * $perKm;
        }

        return $fee;
    }

    /** @param list<array<string, mixed>> $drafts */
    private function singleCurrency(array $drafts): string
    {
        $currencies = array_unique(array_map(fn ($d) => $d['attributes']['currency'], $drafts));
        if (count($currencies) > 1) {
            throw ValidationException::withMessages(['lines' => 'All items in one checkout must use the same currency.']);
        }

        return $currencies[0];
    }

    /** BK-26-004812: year plus a sequence. Uniqueness is enforced by the database index. */
    private function nextNumber(): string
    {
        $prefix = 'BK-'.now()->format('y').'-';

        do {
            $number = $prefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Booking::where('number', $number)->exists());

        return $number;
    }
}

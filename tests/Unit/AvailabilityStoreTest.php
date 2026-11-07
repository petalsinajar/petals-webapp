<?php

namespace Tests\Unit;

use App\Modules\Catalog\Availability\InMemoryAvailabilityStore;
use App\Modules\Catalog\Availability\InsufficientStockException;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Availability\ReservationWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The contract every AvailabilityStore must honour. Point this at MongoAvailabilityStore
 * (see tests/Integration) to check the production store against a real replica set.
 */
class AvailabilityStoreTest extends TestCase
{
    private InMemoryAvailabilityStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryAvailabilityStore;
    }

    private function request(int $qty, string $from = '2026-12-05 08:00', string $to = '2026-12-05 22:00', string $variant = 'v1', ReservationKind $kind = ReservationKind::Booking): ReservationRequest
    {
        return new ReservationRequest(
            vendorId: 'vendor', itemId: 'item', variantId: $variant, qty: $qty, capacity: 200,
            start: CarbonImmutable::parse($from, 'Africa/Accra'), end: CarbonImmutable::parse($to, 'Africa/Accra'),
            kind: $kind, bookingId: 'booking-'.$qty,
            expiresAt: $kind === ReservationKind::Hold ? CarbonImmutable::now()->addMinutes(15) : null,
        );
    }

    public function test_three_events_can_share_stock_on_the_same_day(): void
    {
        $this->store->reserveMany([$this->request(80)]);
        $this->store->reserveMany([$this->request(70)]);
        $this->store->reserveMany([$this->request(50)]);

        $this->assertSame(['2026-12-05' => 0], $this->store->remaining('v1', 200, ['2026-12-05']));
    }

    public function test_it_refuses_to_oversell_and_reports_the_day(): void
    {
        $this->store->reserveMany([$this->request(180)]);

        try {
            $this->store->reserveMany([$this->request(50)]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame('2026-12-05', $e->day);
            $this->assertSame(20, $e->available);
        }
    }

    public function test_reserving_many_is_all_or_nothing(): void
    {
        $this->store->reserveMany([$this->request(190, variant: 'v2')]);

        try {
            $this->store->reserveMany([$this->request(10, variant: 'v1'), $this->request(20, variant: 'v2')]);
        } catch (InsufficientStockException) {
        }

        // v1 was not reserved because v2 failed in the same request.
        $this->assertSame(['2026-12-05' => 200], $this->store->remaining('v1', 200, ['2026-12-05']));
    }

    public function test_release_gives_stock_back_once(): void
    {
        [$reservation] = $this->store->reserveMany([$this->request(100)]);

        $this->assertTrue($this->store->release($reservation->id));
        $this->assertFalse($this->store->release($reservation->id));
        $this->assertSame(['2026-12-05' => 200], $this->store->remaining('v1', 200, ['2026-12-05']));
    }

    public function test_multi_day_windows_hold_every_day(): void
    {
        $this->store->reserveMany([$this->request(150, '2026-12-05 08:00', '2026-12-07 12:00')]);

        $this->assertSame(
            ['2026-12-05' => 50, '2026-12-06' => 50, '2026-12-07' => 50, '2026-12-08' => 200],
            $this->store->remaining('v1', 200, ['2026-12-05', '2026-12-06', '2026-12-07', '2026-12-08']),
        );
    }

    public function test_holds_expire_and_confirm_turns_a_hold_into_a_booking(): void
    {
        [$hold] = $this->store->reserveMany([$this->request(10, kind: ReservationKind::Hold)]);

        $this->assertSame([], $this->store->expiredHolds(CarbonImmutable::now()));
        $this->assertCount(1, $this->store->expiredHolds(CarbonImmutable::now()->addHour()));

        $this->assertTrue($this->store->confirm($hold->id));
        $this->assertSame([], $this->store->expiredHolds(CarbonImmutable::now()->addHour()));
    }

    public function test_lowering_capacity_reports_overbooked_days(): void
    {
        $this->store->reserveMany([$this->request(150)]);

        $this->assertSame(['2026-12-05'], $this->store->setCapacity('v1', 100, '2026-12-01'));
    }

    public function test_window_ending_at_midnight_does_not_take_the_next_day(): void
    {
        $days = ReservationWindow::days(
            CarbonImmutable::parse('2026-12-05 10:00', 'Africa/Accra'),
            CarbonImmutable::parse('2026-12-06 00:00', 'Africa/Accra'),
        );

        $this->assertSame(['2026-12-05'], $days);
    }
}

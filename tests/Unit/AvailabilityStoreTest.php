<?php

namespace Tests\Unit;

use App\Modules\Catalog\Availability\InsufficientStockException;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Availability\SqlAvailabilityStore;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The stock ledger's rules, against the real tables (SQLite by default, or PostgreSQL). */
class AvailabilityStoreTest extends TestCase
{
    use RefreshDatabase;

    private SqlAvailabilityStore $ledger;

    private Item $item;

    /** @var array<string, ItemVariant> */
    private array $variants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new SqlAvailabilityStore;
        $this->item = Item::factory()->create();
        $this->variants = [
            'v1' => ItemVariant::factory()->for($this->item)->create(['quantity' => 200]),
            'v2' => ItemVariant::factory()->for($this->item)->create(['quantity' => 200]),
        ];
    }

    private function request(int $qty, string $from = '2026-12-05 08:00', string $to = '2026-12-05 22:00', string $variant = 'v1', ReservationKind $kind = ReservationKind::Booking, int $capacity = 200, ?string $booking = null): ReservationRequest
    {
        return new ReservationRequest(
            vendorId: $this->item->vendor_id, itemId: $this->item->id, variantId: $this->variants[$variant]->id,
            qty: $qty, capacity: $capacity,
            start: CarbonImmutable::parse($from, 'Africa/Accra'), end: CarbonImmutable::parse($to, 'Africa/Accra'),
            kind: $kind, bookingId: $booking,
            expiresAt: $kind === ReservationKind::Hold ? CarbonImmutable::now()->addMinutes(15) : null,
        );
    }

    /**
     * @param  list<string>  $days
     * @return array<string, int>
     */
    private function left(string $variant = 'v1', array $days = ['2026-12-05']): array
    {
        return $this->ledger->remaining($this->variants[$variant]->id, 200, $days);
    }

    public function test_three_events_can_share_stock_on_the_same_day(): void
    {
        $this->ledger->reserveMany([$this->request(80)]);
        $this->ledger->reserveMany([$this->request(70)]);
        $this->ledger->reserveMany([$this->request(50)]);

        $this->assertSame(['2026-12-05' => 0], $this->left());
    }

    public function test_it_refuses_to_oversell_and_reports_the_day(): void
    {
        $this->ledger->reserveMany([$this->request(180)]);

        try {
            $this->ledger->reserveMany([$this->request(50)]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame('2026-12-05', $e->day);
            $this->assertSame(20, $e->available);
        }
    }

    public function test_reserving_many_is_all_or_nothing(): void
    {
        $this->ledger->reserveMany([$this->request(190, variant: 'v2')]);

        try {
            $this->ledger->reserveMany([$this->request(10, variant: 'v1'), $this->request(20, variant: 'v2')]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        // v1 was not reserved because v2 failed in the same request: no counter change, no row.
        $this->assertSame(['2026-12-05' => 200], $this->left('v1'));
        $this->assertSame(1, DB::table('reservations')->count());
    }

    public function test_the_guard_uses_the_counter_not_the_callers_capacity(): void
    {
        // The counter is created with the stock at the time (200)...
        $this->ledger->reserveMany([$this->request(150)]);

        // ...so a caller holding a stale, larger capacity still can't push past it.
        $this->expectException(InsufficientStockException::class);
        $this->ledger->reserveMany([$this->request(60, capacity: 1000)]);
    }

    public function test_lines_for_the_same_variant_are_summed_before_checking(): void
    {
        $this->ledger->reserveMany([$this->request(100)]);

        // 60 + 60 each fit the 100 left on their own; together they must fail.
        try {
            $this->ledger->reserveMany([$this->request(60), $this->request(60)]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(120, $e->requested);
            $this->assertSame(100, $e->available);
        }
        $this->assertSame(['2026-12-05' => 100], $this->left());
    }

    public function test_a_rollback_in_the_callers_transaction_undoes_the_reservation(): void
    {
        try {
            DB::transaction(function () {
                $this->ledger->reserveMany([$this->request(150)]);
                throw new \RuntimeException('writing the booking failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(['2026-12-05' => 200], $this->left());
        $this->assertSame(0, DB::table('reservations')->count());
    }

    public function test_release_gives_stock_back_once(): void
    {
        [$reservation] = $this->ledger->reserveMany([$this->request(100)]);

        $this->assertTrue($this->ledger->release($reservation->id));
        $this->assertFalse($this->ledger->release($reservation->id));
        $this->assertSame(['2026-12-05' => 200], $this->left());
    }

    public function test_release_for_booking_is_idempotent_across_variants(): void
    {
        $this->ledger->reserveMany([
            $this->request(30, variant: 'v1', booking: '01kbookingone0000000000000'),
            $this->request(40, variant: 'v2', booking: '01kbookingone0000000000000'),
            $this->request(10, variant: 'v1', booking: '01kbookingtwo0000000000000'),
        ]);

        $this->assertSame(2, $this->ledger->releaseForBooking('01kbookingone0000000000000'));
        $this->assertSame(0, $this->ledger->releaseForBooking('01kbookingone0000000000000'));
        $this->assertSame(['2026-12-05' => 190], $this->left('v1'));
        $this->assertSame(['2026-12-05' => 200], $this->left('v2'));
    }

    public function test_multi_day_windows_hold_every_day(): void
    {
        $this->ledger->reserveMany([$this->request(150, '2026-12-05 08:00', '2026-12-07 12:00')]);

        $this->assertSame(
            ['2026-12-05' => 50, '2026-12-06' => 50, '2026-12-07' => 50, '2026-12-08' => 200],
            $this->left(days: ['2026-12-05', '2026-12-06', '2026-12-07', '2026-12-08']),
        );
    }

    public function test_holds_expire_and_confirm_turns_a_hold_into_a_booking(): void
    {
        [$hold] = $this->ledger->reserveMany([$this->request(10, kind: ReservationKind::Hold)]);

        $this->assertSame([], $this->ledger->expiredHolds(CarbonImmutable::now()));
        $this->assertCount(1, $this->ledger->expiredHolds(CarbonImmutable::now()->addHour()));

        $this->assertTrue($this->ledger->confirm($hold->id));
        $this->assertTrue($this->ledger->confirm($hold->id), 'confirming twice still reports a confirmed booking');
        $this->assertSame(ReservationKind::Booking, $this->ledger->find($hold->id)->kind);
        $this->assertSame([], $this->ledger->expiredHolds(CarbonImmutable::now()->addHour()));
    }

    public function test_released_holds_cannot_be_confirmed_or_extended(): void
    {
        [$hold] = $this->ledger->reserveMany([$this->request(10, kind: ReservationKind::Hold)]);
        $this->ledger->release($hold->id);

        $this->assertFalse($this->ledger->confirm($hold->id));
        $this->assertFalse($this->ledger->extendHold($hold->id, CarbonImmutable::now()->addDay()));
    }

    public function test_blocks_take_stock_and_cannot_be_confirmed(): void
    {
        [$block] = $this->ledger->reserveMany([$this->request(150, kind: ReservationKind::Block)]);

        $this->assertSame(['2026-12-05' => 50], $this->left());
        $this->assertFalse($this->ledger->confirm($block->id));
        $this->assertSame(ReservationKind::Block, $this->ledger->find($block->id)->kind);
    }

    public function test_lowering_capacity_reports_overbooked_days(): void
    {
        $this->ledger->reserveMany([$this->request(150)]);

        $this->assertSame(['2026-12-05'], $this->ledger->setCapacity($this->variants['v1']->id, 100, '2026-12-01'));
        $this->assertSame([], $this->ledger->setCapacity($this->variants['v1']->id, 160, '2026-12-01'));
        $this->assertSame(['2026-12-05' => 10], $this->left());
    }

    public function test_for_vendor_lists_active_reservations_in_the_window(): void
    {
        [$a] = $this->ledger->reserveMany([$this->request(5)]);
        $this->ledger->reserveMany([$this->request(5, '2027-01-10 08:00', '2027-01-10 20:00')]);
        [$released] = $this->ledger->reserveMany([$this->request(5)]);
        $this->ledger->release($released->id);

        $found = $this->ledger->forVendor($this->item->vendor_id, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31'));

        $this->assertSame([$a->id], array_map(fn ($r) => $r->id, $found));
        $this->assertTrue($a->start->equalTo($found[0]->start));
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

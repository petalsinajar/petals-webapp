<?php

namespace Tests\Integration;

use App\Modules\Catalog\Availability\InsufficientStockException;
use App\Modules\Catalog\Availability\MongoAvailabilityStore;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the production stock ledger against a real MongoDB replica set.
 *
 *   MONGODB_TEST_URI="mongodb://127.0.0.1:27017/?replicaSet=rs0" php artisan test --testsuite=Integration
 *
 * Skipped when MONGODB_TEST_URI isn't set. Uses a throwaway database.
 */
class MongoAvailabilityStoreTest extends TestCase
{
    private MongoAvailabilityStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('mongodb') || ! env('MONGODB_TEST_URI')) {
            $this->markTestSkipped('Set MONGODB_TEST_URI to run MongoDB integration tests.');
        }

        config(['database.connections.mongodb_test' => [
            'driver' => 'mongodb',
            'dsn' => env('MONGODB_TEST_URI'),
            'database' => 'event_app_test_'.getmypid(),
        ]]);

        $db = DB::connection('mongodb_test')->getDatabase();
        $db->createCollection('availability_days');
        $db->createCollection('reservations');
        $db->selectCollection('availability_days')->createIndex(['variant_id' => 1, 'day' => 1], ['unique' => true]);

        $this->store = new MongoAvailabilityStore('mongodb_test');
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            DB::connection('mongodb_test')->getDatabase()->drop();
        }
        parent::tearDown();
    }

    private function request(int $qty, string $variant = '64b000000000000000000001'): ReservationRequest
    {
        return new ReservationRequest(
            vendorId: 'vendor', itemId: '64b0000000000000000000ff', variantId: $variant, qty: $qty, capacity: 100,
            start: CarbonImmutable::parse('2026-12-05 08:00', 'Africa/Accra'),
            end: CarbonImmutable::parse('2026-12-05 22:00', 'Africa/Accra'),
            kind: ReservationKind::Hold, bookingId: 'b'.$qty, expiresAt: CarbonImmutable::now()->addMinutes(15),
        );
    }

    public function test_reserve_release_and_refuse_oversell(): void
    {
        [$a] = $this->store->reserveMany([$this->request(60)]);

        $this->expectException(InsufficientStockException::class);
        try {
            $this->store->reserveMany([$this->request(50)]);
        } finally {
            $this->assertSame(['2026-12-05' => 40], $this->store->remaining('64b000000000000000000001', 100, ['2026-12-05']));
            $this->assertTrue($this->store->release($a->id));
            $this->assertFalse($this->store->release($a->id));
            $this->assertSame(['2026-12-05' => 100], $this->store->remaining('64b000000000000000000001', 100, ['2026-12-05']));
        }
    }

    public function test_reserve_many_rolls_back_every_line_on_failure(): void
    {
        $this->store->reserveMany([$this->request(95, '64b000000000000000000002')]);

        try {
            $this->store->reserveMany([$this->request(10, '64b000000000000000000001'), $this->request(10, '64b000000000000000000002')]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(['2026-12-05' => 100], $this->store->remaining('64b000000000000000000001', 100, ['2026-12-05']));
        $this->assertCount(1, $this->store->forVendor('vendor', CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31')));
    }

    public function test_confirm_and_expiry(): void
    {
        [$hold] = $this->store->reserveMany([$this->request(5)]);

        $this->assertCount(1, $this->store->expiredHolds(CarbonImmutable::now()->addHour()));
        $this->assertTrue($this->store->confirm($hold->id));
        $this->assertCount(0, $this->store->expiredHolds(CarbonImmutable::now()->addHour()));
        $this->assertSame(ReservationKind::Booking, $this->store->find($hold->id)->kind);
    }
}

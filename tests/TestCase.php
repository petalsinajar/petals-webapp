<?php

namespace Tests;

use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\InMemoryAvailabilityStore;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Catalog\Services\InMemoryCatalogReader;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

/**
 * Tests run on SQLite in memory, with inventory in memory (INVENTORY_DRIVER=memory),
 * so MongoDB isn't needed to run the suite.
 */
abstract class TestCase extends BaseTestCase
{
    protected function catalog(): InMemoryCatalogReader
    {
        return $this->app->make(CatalogReader::class);
    }

    protected function store(): InMemoryAvailabilityStore
    {
        return $this->app->make(AvailabilityStore::class);
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['access']);

        return $user;
    }

    /** Register a rentable variant in the in-memory catalog. */
    protected function variant(Vendor $vendor, array $overrides = []): VariantSnapshot
    {
        $snapshot = new VariantSnapshot(...array_merge([
            'itemId' => bin2hex(random_bytes(12)),
            'variantId' => bin2hex(random_bytes(12)),
            'vendorId' => $vendor->id,
            'title' => 'Chiavari chair (Gold)',
            'quantity' => 100,
            'unitPriceMinor' => 1500,
            'currency' => 'GHS',
            'minQty' => 1,
            'damageDepositMinor' => 0,
            'instantBook' => false,
            'requiresSetup' => false,
            'active' => true,
        ], $overrides));

        $this->catalog()->put($snapshot);

        return $snapshot;
    }
}

<?php

namespace Tests;

use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\SqlAvailabilityStore;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\SqlCatalogReader;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

/**
 * Tests run on SQLite in memory by default, with the real SQL stock ledger and catalog.
 * The same suite runs on PostgreSQL + PostGIS (see README, "Tests").
 */
abstract class TestCase extends BaseTestCase
{
    protected function catalog(): SqlCatalogReader
    {
        return $this->app->make(CatalogReader::class);
    }

    protected function store(): SqlAvailabilityStore
    {
        return $this->app->make(AvailabilityStore::class);
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['access']);

        return $user;
    }

    /**
     * Create an item with one variant for a vendor and return its snapshot. Overrides use the
     * snapshot's names (quantity, unitPriceMinor, instantBook, ...); prices are flat per unit.
     */
    protected function variant(Vendor $vendor, array $overrides = [], string $category = 'chairs'): VariantSnapshot
    {
        $o = $overrides + [
            'title' => 'Chiavari chair',
            'quantity' => 100,
            'unitPriceMinor' => 1500,
            'currency' => 'GHS',
            'minQty' => 1,
            'damageDepositMinor' => 0,
            'instantBook' => false,
            'requiresSetup' => false,
            'active' => true,
        ];

        $item = Item::factory()->for($vendor)->inCategory($category)
            ->withVariant(['name' => 'Gold', 'quantity' => $o['quantity']])
            ->create([
                'title' => $o['title'],
                'price_tiers' => [['min_qty' => 1, 'price_minor' => $o['unitPriceMinor']]],
                'currency' => $o['currency'],
                'min_qty' => $o['minQty'],
                'damage_deposit_minor' => $o['damageDepositMinor'],
                'instant_book' => $o['instantBook'],
                'requires_setup' => $o['requiresSetup'],
                'status' => $o['active'] ? 'active' : 'paused',
            ]);

        return $this->catalog()->variant($item->id, $item->variants()->value('id'));
    }
}

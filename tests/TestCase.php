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
use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Storage\FakeMediaStorage;
use App\Modules\Media\Video\FakeVideoProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

/**
 * Tests run on SQLite in memory by default, with the real SQL stock ledger and catalog.
 * The same suite runs on PostgreSQL + PostGIS (see README, "Tests").
 */
abstract class TestCase extends BaseTestCase
{
    /** Whoever signIn() last signed in. */
    protected ?User $signedIn = null;

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

        return $this->signedIn = $user;
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

    protected function mediaStorage(): FakeMediaStorage
    {
        return $this->app->make(MediaStorage::class);
    }

    protected function videoProvider(): FakeVideoProvider
    {
        return $this->app->make(VideoProvider::class);
    }

    /** An upload owned by $owner, ready by default (as if processed), for attaching in tests. */
    protected function media(User $owner, string $kind = 'image', MediaStatus $status = MediaStatus::Ready, ?Vendor $vendor = null): Media
    {
        $ready = $status === MediaStatus::Ready;

        return Media::create([
            'owner_id' => $owner->id,
            'vendor_id' => $vendor?->id,
            'kind' => $kind,
            'purpose' => 'post',
            'status' => $status,
            'mime' => $kind === 'image' ? 'image/jpeg' : 'video/mp4',
            'bytes' => 120_000,
            'width' => 1080,
            'height' => 1350,
            'blurhash' => $ready ? 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' : null,
            'variants' => match (true) {
                ! $ready => null,
                $kind === 'image' => ['thumb' => 'media/t/thumb.webp', 'feed' => 'media/t/feed.webp', 'full' => 'media/t/full.webp'],
                default => ['hls' => 'v/manifest/video.m3u8', 'poster' => 'v/thumbnails/thumbnail.jpg'],
            },
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Services\CapacityWarnings;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        Category::create(['id' => 'chairs', 'name' => 'Chairs']);
        Category::create(['id' => 'sound', 'name' => 'Sound']);
    }

    /** @return array<string, mixed> */
    private function itemPayload(array $overrides = []): array
    {
        return [
            'category_id' => 'chairs',
            'title' => 'Chiavari chair',
            'pricing' => ['unit' => 'per_event', 'tiers' => [['min_qty' => 1, 'price_minor' => 1500], ['min_qty' => 100, 'price_minor' => 1200]]],
            'specs' => ['material' => 'resin'],
            'variants' => [['name' => 'Gold', 'quantity' => 200], ['name' => 'White', 'quantity' => 50]],
            'status' => 'active',
            ...$overrides,
        ];
    }

    private function createItem(array $overrides = []): array
    {
        $this->signIn($this->owner);

        return $this->postJson("/v1/vendors/{$this->vendor->id}/items", $this->itemPayload($overrides))->assertCreated()->json();
    }

    /** Book stock on the event's day through checkout, the way hosts do. */
    private function book(string $itemId, string $variantId, int $qty): string
    {
        $host = User::factory()->create();
        $event = Event::factory()->create(['host_id' => $host->id, 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(6)]);
        $this->signIn($host);
        $this->postJson('/v1/checkouts', ['event_id' => $event->id, 'lines' => [['item_id' => $itemId, 'variant_id' => $variantId, 'qty' => $qty]]])->assertCreated();
        $this->signIn($this->owner);

        return $event->starts_at->setTimezone('Africa/Accra')->toDateString();
    }

    public function test_vendor_creates_an_item_with_variants_and_tier_pricing(): void
    {
        $item = $this->createItem();

        $this->assertSame(['unit' => 'per_event', 'currency' => 'GHS', 'tiers' => [['min_qty' => 1, 'price_minor' => 1500], ['min_qty' => 100, 'price_minor' => 1200]]], $item['pricing']);
        $this->assertSame(['Gold', 'White'], array_column($item['variants'], 'name'));
        $this->assertSame(['material' => 'resin'], $item['specs']);

        $model = Item::find($item['id']);
        $this->assertSame(1500, $model->unitPriceFor(10));
        $this->assertSame(1200, $model->unitPriceFor(150));

        $this->getJson("/v1/items/{$item['id']}")->assertOk()->assertJsonCount(2, 'variants');
    }

    public function test_update_matches_variants_by_id_adds_new_ones_and_deletes_unused_ones(): void
    {
        $item = $this->createItem();
        [$gold, $white] = $item['variants'];

        $updated = $this->patchJson("/v1/vendors/{$this->vendor->id}/items/{$item['id']}", ['variants' => [
            ['id' => $gold['id'], 'name' => 'Gold', 'quantity' => 250],
            ['name' => 'Clear', 'quantity' => 30],
        ]])->assertOk()->json();

        $this->assertSame($gold['id'], $updated['variants'][0]['id']);
        $this->assertSame(250, $updated['variants'][0]['quantity']);
        $this->assertSame('Clear', $updated['variants'][1]['name']);
        $this->assertSame([], $updated['warnings']);
        $this->assertNull(ItemVariant::find($white['id']), 'an unused variant is really deleted');
    }

    public function test_variants_used_by_bookings_are_deactivated_never_deleted(): void
    {
        $item = $this->createItem();
        [$gold, $white] = $item['variants'];
        $this->book($item['id'], $white['id'], 10);

        $updated = $this->patchJson("/v1/vendors/{$this->vendor->id}/items/{$item['id']}", ['variants' => [
            ['id' => $gold['id'], 'name' => 'Gold', 'quantity' => 200],
        ]])->assertOk()->json();

        $kept = ItemVariant::find($white['id']);
        $this->assertNotNull($kept);
        $this->assertFalse($kept->active);
        $this->assertSame([true, false], array_column($updated['variants'], 'active'));
        $this->assertSame([$gold['id']], array_map(fn ($v) => $v->variantId, $this->catalog()->activeVariantsInCategory('chairs', [$this->vendor->id])));
    }

    public function test_a_variant_id_from_another_item_is_rejected(): void
    {
        $item = $this->createItem();
        $other = $this->createItem(['title' => 'Other']);

        $this->patchJson("/v1/vendors/{$this->vendor->id}/items/{$item['id']}", ['variants' => [
            ['id' => $other['variants'][0]['id'], 'name' => 'Stolen', 'quantity' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('variants.0.id');
    }

    public function test_lowering_quantity_below_bookings_returns_warnings(): void
    {
        $item = $this->createItem();
        [$gold, $white] = $item['variants'];
        $day = $this->book($item['id'], $gold['id'], 150);

        $updated = $this->patchJson("/v1/vendors/{$this->vendor->id}/items/{$item['id']}", ['variants' => [
            ['id' => $gold['id'], 'name' => 'Gold', 'quantity' => 100],
            ['id' => $white['id'], 'name' => 'White', 'quantity' => 40],
        ]])->assertOk()->json();

        $this->assertCount(1, $updated['warnings']);
        $this->assertSame($gold['id'], $updated['warnings'][0]['variant_id']);
        $this->assertContains($day, $updated['warnings'][0]['days']); // plus the buffer days around it
        $this->assertSame([$day => 0], $this->store()->remaining($gold['id'], 100, [$day]));
    }

    public function test_quantity_changes_saved_outside_the_api_reach_the_counters_too(): void
    {
        // What the Filament relationship repeater does: save the variant model directly.
        $item = $this->createItem();
        $gold = ItemVariant::find($item['variants'][0]['id']);
        $day = $this->book($item['id'], $gold->id, 150);

        $gold->update(['quantity' => 120]);

        $warnings = app(CapacityWarnings::class)->pull($item['id']);
        $this->assertCount(1, $warnings);
        $this->assertSame($gold->id, $warnings[0]['variant_id']);
        $this->assertContains($day, $warnings[0]['days']);
        $this->assertSame([], app(CapacityWarnings::class)->pull($item['id']), 'warnings are handed out once');
    }

    public function test_blocks_take_stock_and_can_be_removed(): void
    {
        $item = $this->createItem();
        $white = $item['variants'][1];
        $block = ['item_id' => $item['id'], 'variant_id' => $white['id'], 'start' => '2026-12-05T08:00:00+00:00', 'end' => '2026-12-05T20:00:00+00:00', 'reason' => 'maintenance'];

        $id = $this->postJson("/v1/vendors/{$this->vendor->id}/blocks", [...$block, 'qty' => 45])->assertCreated()->assertJsonPath('kind', 'block')->json('id');
        $this->postJson("/v1/vendors/{$this->vendor->id}/blocks", [...$block, 'qty' => 10])->assertStatus(409)->assertJsonPath('stock.available', 5);

        $this->getJson("/v1/items/{$item['id']}/availability?variant_id={$white['id']}&from=2026-12-05&to=2026-12-05")
            ->assertJsonPath('days.0.available', 5);
        $this->getJson("/v1/vendors/{$this->vendor->id}/calendar?from=2026-12-01&to=2026-12-31")->assertJsonCount(1, 'data');

        // Another vendor can't remove it.
        $stranger = User::factory()->create();
        $strangerVendor = Vendor::factory()->ownedBy($stranger)->create();
        $this->signIn($stranger);
        $this->deleteJson("/v1/vendors/{$strangerVendor->id}/blocks/{$id}")->assertNotFound();

        $this->signIn($this->owner);
        $this->deleteJson("/v1/vendors/{$this->vendor->id}/blocks/{$id}")->assertOk();
        $this->getJson("/v1/items/{$item['id']}/availability?variant_id={$white['id']}&from=2026-12-05&to=2026-12-05")
            ->assertJsonPath('days.0.available', 50);
    }

    public function test_a_vendor_serves_a_venue_only_inside_its_own_radius(): void
    {
        // Venue about 20 km north of central Accra. (The setUp vendor rents tents here.)
        $venue = ['lat' => 5.7837, 'lng' => -0.1870];
        $this->vendor->update(['categories' => ['tents']]);

        $near = Vendor::factory()->create(['name' => 'Near', 'base_lat' => 5.7400, 'base_lng' => -0.1870, 'service_radius_km' => 10, 'categories' => ['chairs']]); // ~5 km
        $wide = Vendor::factory()->create(['name' => 'Wide', 'base_lat' => 5.6037, 'base_lng' => -0.1870, 'service_radius_km' => 40, 'categories' => ['chairs']]); // ~20 km, radius 40
        Vendor::factory()->create(['name' => 'Short', 'base_lat' => 5.6037, 'base_lng' => -0.1870, 'service_radius_km' => 15, 'categories' => ['chairs']]); // ~20 km, radius 15
        Vendor::factory()->create(['name' => 'Kumasi', 'base_lat' => 6.6885, 'base_lng' => -1.6244, 'service_radius_km' => 100, 'categories' => ['chairs']]); // ~190 km
        Vendor::factory()->create(['name' => 'Sound', 'base_lat' => 5.7837, 'base_lng' => -0.1870, 'service_radius_km' => 10, 'categories' => ['sound']]);
        Vendor::factory()->create(['name' => 'Nowhere', 'base_lat' => null, 'base_lng' => null, 'categories' => ['chairs']]);

        $this->signIn();
        $found = $this->getJson('/v1/vendors?'.http_build_query([...$venue, 'category' => 'chairs']))->assertOk()->json('data');

        $this->assertSame(['Near', 'Wide'], array_column($found, 'name'), 'nearest first, own radius only');
        $this->assertEqualsWithDelta(4.9, $found[0]['distance_km'], 0.2);
        $this->assertEqualsWithDelta(20.0, $found[1]['distance_km'], 0.2);

        // Items follow their vendor's service area.
        Item::factory()->for($near)->withVariant()->create(['title' => 'Near chair']);
        Item::factory()->for($wide)->withVariant()->create(['title' => 'Wide chair']);
        Item::factory()->for(Vendor::where('name', 'Short')->first())->withVariant()->create(['title' => 'Short chair']);

        $items = $this->getJson('/v1/items?'.http_build_query($venue))->assertOk()->json('data');
        $this->assertSame(['Near chair', 'Wide chair'], array_column($items, 'title'));
    }

    public function test_vendor_search_needs_a_location(): void
    {
        $this->signIn();
        $this->getJson('/v1/vendors')->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_packages_reference_the_vendors_own_variants(): void
    {
        $item = $this->createItem();
        $foreign = Item::factory()->withVariant()->create();

        $this->postJson("/v1/vendors/{$this->vendor->id}/packages", [
            'title' => 'Wedding for 100', 'price_minor' => 150000,
            'lines' => [['item_id' => $item['id'], 'variant_id' => $item['variants'][0]['id'], 'qty' => 100]],
        ])->assertCreated()->assertJsonPath('lines.0.qty', 100);

        $this->postJson("/v1/vendors/{$this->vendor->id}/packages", [
            'title' => 'Borrowed', 'price_minor' => 1,
            'lines' => [['item_id' => $foreign->id, 'variant_id' => $foreign->variants()->value('id'), 'qty' => 1]],
        ])->assertNotFound();
    }
}

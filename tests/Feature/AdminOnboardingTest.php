<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Items\ItemResource;
use App\Filament\Admin\Resources\Items\Pages\ManageItems;
use App\Filament\Admin\Resources\Vendors\Pages\ManageVendors;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The platform team setting up vendors and their inventory from the admin panel. */
class AdminOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
        Category::create(['id' => 'chairs', 'name' => 'Chairs']);
    }

    private function newVendor(array $overrides = []): array
    {
        return $overrides + ['owner_phone' => '024 555 0101', 'owner_name' => 'Kojo Asante', 'name' => 'Royal Canopies', 'city' => 'Accra'];
    }

    public function test_creating_a_vendor_sets_up_an_owner_who_can_sign_in_by_phone(): void
    {
        Livewire::test(ManageVendors::class)
            ->callAction('create', $this->newVendor())
            ->assertHasNoActionErrors();

        $vendor = Vendor::where('name', 'Royal Canopies')->sole();
        $owner = User::where('phone', '+233245550101')->sole();
        $this->assertSame('royal.canopies', $vendor->handle);
        $this->assertSame('Kojo Asante', $owner->display_name);
        $this->assertSame(VendorRole::Owner, $owner->roleIn($vendor));
        $this->assertSame(['type' => 'vendor', 'id' => $vendor->id], (array) DB::table('handles')->where('handle', 'royal.canopies')->first(['owner_type as type', 'owner_id as id']));
        $this->assertSame('starter', $vendor->plan_key);
    }

    public function test_an_existing_account_with_that_phone_becomes_the_owner(): void
    {
        $user = User::factory()->create(['phone' => '+233245550101']);

        Livewire::test(ManageVendors::class)
            ->callAction('create', $this->newVendor(['handle' => '@RoyalGH']))
            ->assertHasNoActionErrors();

        $vendor = Vendor::where('handle', 'royalgh')->sole();
        $this->assertSame(VendorRole::Owner, $user->roleIn($vendor));
        $this->assertSame(1, User::where('phone', '+233245550101')->count());
    }

    public function test_bad_phone_or_taken_handle_is_rejected(): void
    {
        Vendor::factory()->create(['handle' => 'taken']);

        Livewire::test(ManageVendors::class)
            ->callAction('create', $this->newVendor(['owner_phone' => 'nope', 'handle' => 'taken']))
            ->assertHasActionErrors(['owner_phone', 'handle']);

        $this->assertSame(0, Vendor::where('name', 'Royal Canopies')->count());
    }

    public function test_admin_sees_every_vendors_items_and_can_filter_by_vendor(): void
    {
        $a = Item::factory()->create(['title' => 'Gold canopy']);
        $b = Item::factory()->create(['title' => 'Fairy-light curtain']);

        Livewire::test(ManageItems::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->filterTable('vendor', $a->vendor_id)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);

        // The vendor row's "Inventory" link opens the list already filtered.
        $this->get(ItemResource::getUrl('index', ['filters' => ['vendor' => ['value' => $a->vendor_id]]]))
            ->assertOk()->assertSee($a->title)->assertDontSee($b->title);
    }

    public function test_admin_adds_items_for_a_vendor_even_past_the_plan_limit(): void
    {
        $vendor = Vendor::factory()->create(); // Starter: 10 items
        Item::factory()->count(10)->create(['vendor_id' => $vendor->id]);

        Livewire::test(ManageItems::class)
            ->callAction('create', [
                'vendor_id' => $vendor->id,
                'title' => 'White Chiavari chair',
                'category_id' => 'chairs',
                'status' => 'active',
                'pricing_unit' => 'per_event',
                'currency' => 'GHS',
                'min_qty' => 1,
                'price_tiers' => [['min_qty' => 1, 'price_minor' => 1500]],
                'variants' => [['name' => 'White', 'quantity' => 200, 'price_delta_minor' => 0, 'active' => true]],
            ])
            ->assertHasNoActionErrors();

        $item = Item::where('title', 'White Chiavari chair')->sole();
        $this->assertSame($vendor->id, $item->vendor_id);
        $this->assertSame(200, (int) $item->variants()->sum('quantity'));
    }
}

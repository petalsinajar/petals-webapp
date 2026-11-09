<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Package;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Discover tab's reads: browsable signed out, near a point, filtered by event type. */
class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    /** East Legon. */
    private const LAT = 5.6350;

    private const LNG = -0.1570;

    private function vendor(array $attributes = []): Vendor
    {
        $vendor = Vendor::factory()->ownedBy()->create(['name' => 'Royal Canopies', ...$attributes]);
        $vendor->forceFill(['verification_status' => 'verified', 'status' => 'active'])->save();

        return $vendor;
    }

    public function test_catalog_and_vendors_can_be_browsed_signed_out(): void
    {
        $vendor = $this->vendor();
        $this->variant($vendor);

        $this->getJson('/v1/categories')->assertOk();
        $this->getJson('/v1/items?lat='.self::LAT.'&lng='.self::LNG)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/v1/vendors?lat='.self::LAT.'&lng='.self::LNG)->assertOk()
            ->assertJsonPath('data.0.is_following', false);
        $this->getJson("/v1/vendors/{$vendor->id}")->assertOk()->assertJsonPath('is_following', false);
        $this->getJson('/v1/packages?lat='.self::LAT.'&lng='.self::LNG)->assertOk();
    }

    public function test_items_carry_their_vendor_and_from_price_and_page_by_offset_near_a_point(): void
    {
        $vendor = $this->vendor();
        foreach (range(1, 3) as $i) {
            $this->variant($vendor, ['title' => "Chair $i", 'unitPriceMinor' => 1200]);
        }
        $this->variant($this->vendor(['base_lat' => 6.6885, 'base_lng' => -1.6244, 'city' => 'Kumasi']));

        $first = $this->getJson('/v1/items?limit=2&lat='.self::LAT.'&lng='.self::LNG)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.vendor.name', 'Royal Canopies')
            ->assertJsonPath('data.0.from_price_minor', 1200)
            ->assertJsonPath('next_cursor', 'o:2');

        $this->getJson('/v1/items?limit=2&cursor=o:2&lat='.self::LAT.'&lng='.self::LNG)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('next_cursor', null);
        $this->assertNotContains($first->json('data.0.id'), [$this->getJson('/v1/items?limit=2&cursor=o:2&lat='.self::LAT.'&lng='.self::LNG)->json('data.0.id')]);
    }

    public function test_a_parent_category_finds_its_children(): void
    {
        Category::create(['id' => 'seating', 'name' => 'Seating']);
        Category::create(['id' => 'chairs', 'name' => 'Chairs', 'parent_id' => 'seating']);
        $vendor = $this->vendor(['categories' => ['chairs']]);
        $this->variant($vendor, category: 'chairs');
        $this->variant($vendor, ['title' => 'Speaker'], 'sound');

        $this->getJson('/v1/items?category=seating')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Chiavari chair');
        $this->getJson('/v1/vendors?category=seating&lat='.self::LAT.'&lng='.self::LNG)->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_vendors_can_be_searched_by_name_and_show_whether_i_follow_them(): void
    {
        $royal = $this->vendor();
        $this->vendor(['name' => 'Glow Decor']);
        $me = $this->signIn();
        DB::table('follows')->insert([
            'id' => (string) str()->ulid(), 'follower_type' => Actor::USER, 'follower_id' => $me->id,
            'target_type' => Actor::VENDOR, 'target_id' => $royal->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/v1/vendors?q=royal')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $royal->id)
            ->assertJsonPath('data.0.distance_km', null)
            ->assertJsonPath('data.0.is_following', true);
        $this->getJson('/v1/vendors')->assertStatus(422);
    }

    public function test_packages_near_a_point_with_their_vendor_filtered_by_event_type(): void
    {
        $vendor = $this->vendor();
        $far = $this->vendor(['base_lat' => 6.6885, 'base_lng' => -1.6244]);
        foreach ([[$vendor, 'birthday', 'Kids’ party for 30'], [$vendor, 'wedding', 'Garden wedding'], [$far, 'birthday', 'Far away']] as [$owner, $type, $title]) {
            Package::create([
                'vendor_id' => $owner->id, 'title' => $title, 'event_types' => [$type],
                'guest_min' => 20, 'guest_max' => 30, 'price_minor' => 250000, 'currency' => 'GHS',
            ]);
        }

        $this->getJson('/v1/packages?event_type=birthday&lat='.self::LAT.'&lng='.self::LNG)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Kids’ party for 30')
            ->assertJsonPath('data.0.vendor.name', 'Royal Canopies')
            ->assertJsonPath('data.0.guest_range', ['min' => 20, 'max' => 30]);
    }

    public function test_hosts_see_what_their_event_still_needs_with_vendors_free_that_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
        $host = $this->signIn();
        $starts = now()->addDays(30)->setTime(14, 0);
        $event = Event::factory()->create([
            'host_id' => $host->id, 'type' => 'corporate', 'starts_at' => $starts, 'ends_at' => $starts->copy()->addHours(6),
        ]);
        $this->variant($this->vendor(), ['title' => 'PA system', 'unitPriceMinor' => 30000], 'sound');

        $needs = $this->getJson("/v1/events/{$event->id}/needs")->assertOk()->json('data');

        $this->assertSame(['chairs', 'tables', 'staging', 'sound'], array_column($needs, 'category_id'));
        $sound = collect($needs)->firstWhere('category_id', 'sound');
        $this->assertSame('Royal Canopies', $sound['vendors'][0]['vendor']['name']);
        $this->assertSame(30000, $sound['vendors'][0]['from_price_minor']);

        $this->signIn(User::factory()->create());
        $this->getJson("/v1/events/{$event->id}/needs")->assertForbidden();
    }

    public function test_event_search_finds_public_events_and_mine_but_not_others_private_ones(): void
    {
        $me = $this->signIn();
        $later = now()->addDays(10);
        Event::factory()->create(['title' => 'Akosua’s wedding', 'visibility' => 'public', 'starts_at' => $later, 'ends_at' => $later->copy()->addHours(5)]);
        Event::factory()->create(['title' => 'Secret wedding', 'visibility' => 'private', 'starts_at' => $later, 'ends_at' => $later->copy()->addHours(5)]);
        $mine = Event::factory()->create(['title' => 'My wedding', 'visibility' => 'private', 'starts_at' => $later, 'ends_at' => $later->copy()->addHours(5)]);
        $mine->members()->create(['user_id' => $me->id, 'role' => 'guest', 'rsvp' => 'going']);

        $titles = collect($this->getJson('/v1/events?q=wedding')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Akosua’s wedding', 'My wedding'], $titles);
    }
}

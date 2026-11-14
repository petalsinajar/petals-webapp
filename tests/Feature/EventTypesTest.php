<?php

namespace Tests\Feature;

use App\Modules\Events\Models\EventType;
use App\Modules\Identity\Models\User;
use App\Modules\Recommendations\Services\RecommendationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Event types come from the admin-managed table, not a hardcoded list. */
class EventTypesTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(string $type)
    {
        return $this->postJson('/v1/events', [
            'type' => $type,
            'title' => 'Harvest thanksgiving',
            'starts_at' => now()->addMonth()->toIso8601String(),
            'ends_at' => now()->addMonth()->addHours(4)->toIso8601String(),
            'visibility' => 'public',
        ]);
    }

    public function test_the_app_gets_every_type_in_order_signed_out(): void
    {
        $this->getJson('/v1/event-types')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'wedding')
            ->assertJsonPath('data.3.slug', 'religious')
            ->assertJsonPath('data.3.name', 'Religious')
            ->assertJsonPath('data.3.icon', 'church')
            ->assertJsonPath('data.3.active', true)
            ->assertJsonPath('data.4.slug', 'other')
            ->assertJsonPath('data.4.active', false);
    }

    public function test_a_religious_event_can_be_created_and_filtered_on(): void
    {
        $this->signIn();
        $this->createEvent('religious')->assertCreated()->assertJsonPath('type', 'religious');

        $this->getJson('/v1/feed/discover?event_type=religious')->assertOk();
        $this->getJson('/v1/items?event_type=religious')->assertOk();
        $this->getJson('/v1/feed/discover?event_type=space_launch')->assertUnprocessable();
    }

    public function test_a_retired_type_is_not_offered_for_new_events_but_still_filters(): void
    {
        $this->signIn();
        EventType::find('religious')->update(['active' => false]);

        $this->createEvent('religious')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/v1/feed/discover?event_type=religious')->assertOk();
    }

    public function test_a_type_added_in_the_admin_works_straight_away(): void
    {
        $this->signIn();
        $this->createEvent('graduation')->assertUnprocessable();

        // As saving in the admin panel does: the cached list is dropped.
        EventType::create([
            'slug' => 'graduation', 'name' => 'Graduation', 'plural' => 'Graduations',
            'icon' => 'graduation', 'required_categories' => ['chairs', 'tents'], 'sort' => 50,
        ]);

        $this->createEvent('graduation')->assertCreated();
        $this->getJson('/v1/event-types')->assertJsonFragment(['slug' => 'graduation', 'name' => 'Graduation']);
        $this->assertSame(['chairs', 'tents'], RecommendationRules::fromConfig()->config('required_categories.graduation'));
        $this->assertSame(['chairs', 'tents', 'sound', 'staging'], RecommendationRules::fromConfig()->config('required_categories.religious'));
    }

    public function test_only_admins_manage_types(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/event-types')->assertForbidden();
    }
}

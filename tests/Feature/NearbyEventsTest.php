<?php

namespace Tests\Feature;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Happening near you": upcoming public events with a cover, near the viewer. */
class NearbyEventsTest extends TestCase
{
    use RefreshDatabase;

    /** East Legon, Accra. */
    private const ACCRA = ['lat' => 5.6350, 'lng' => -0.1570];

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->create();
    }

    private function event(string $title, array $overrides = []): Event
    {
        return Event::factory()->create([
            'host_id' => $this->host->id,
            'title' => $title,
            'cover_media_id' => $this->media($this->host)->id,
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function titles(array $query = []): array
    {
        return $this->getJson('/v1/events/nearby?'.http_build_query($query))
            ->assertOk()->json('data.*.title');
    }

    public function test_public_events_near_the_place_soonest_first_signed_out(): void
    {
        $this->event('Pinned, in two weeks', ['starts_at' => now()->addDays(14), 'ends_at' => now()->addDays(14)->addHours(4)]);
        $this->event('Typed city, in a week', [
            'venue_lat' => null, 'venue_lng' => null, 'venue_city' => 'east legon',
            'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHours(4),
        ]);
        $this->event('Kumasi', ['venue_lat' => 6.6885, 'venue_lng' => -1.6244]);
        $this->event('Kumasi by name', ['venue_lat' => null, 'venue_lng' => null, 'venue_city' => 'Kumasi']);
        $this->event('No cover', ['cover_media_id' => null]);
        $this->event('Guests only', ['visibility' => 'invite_only']);
        $this->event('Over', ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2)]);
        $this->event('Too far ahead', ['starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(4)]);
        $this->event('Cancelled', ['status' => 'cancelled']);

        $this->assertSame(['Typed city, in a week', 'Pinned, in two weeks'], $this->titles(self::ACCRA));
    }

    public function test_without_a_place_it_is_everywhere(): void
    {
        $this->event('Accra');
        $this->event('Kumasi', ['venue_lat' => 6.6885, 'venue_lng' => -1.6244, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(4)]);

        $this->assertSame(['Kumasi', 'Accra'], $this->titles());
    }

    public function test_signed_in_leaves_out_my_events_and_uses_my_city(): void
    {
        $this->event('Accra party');
        $this->event('Kumasi convention', ['venue_lat' => 6.6885, 'venue_lng' => -1.6244]);
        $going = $this->event('Already going');

        $me = $this->signIn(User::factory()->create(['city' => 'Kumasi']));
        $going->members()->create(['user_id' => $me->id, 'role' => 'guest', 'rsvp' => 'going']);

        $this->assertSame(['Accra party'], $this->titles(self::ACCRA));
        // No place sent: the profile city is used.
        $this->assertSame(['Kumasi convention'], $this->titles());
    }

    public function test_lat_and_lng_come_together(): void
    {
        $this->getJson('/v1/events/nearby?lat=5.6')->assertUnprocessable()->assertJsonValidationErrors('lng');
    }
}

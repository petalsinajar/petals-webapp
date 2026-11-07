<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(array $overrides = []): array
    {
        return $this->postJson('/v1/events', array_merge([
            'type' => 'wedding',
            'title' => 'Ama & Kojo',
            'starts_at' => now()->addMonth()->toIso8601String(),
            'ends_at' => now()->addMonth()->addHours(5)->toIso8601String(),
            'venue_name' => 'Aburi Botanical Gardens',
            'venue_city' => 'Aburi',
            'visibility' => 'public',
        ], $overrides))->assertCreated()->json();
    }

    public function test_events_carry_the_venue_city(): void
    {
        $this->signIn();

        $event = $this->createEvent();

        $this->assertSame('Aburi', $event['venue']['city']);
        $this->assertSame(['going' => 1, 'maybe' => 0, 'declined' => 0], array_intersect_key(
            $event['counts'],
            array_flip(['going', 'maybe', 'declined']),
        ));
    }

    public function test_rsvp_counts_follow_each_answer(): void
    {
        $this->signIn();
        $id = $this->createEvent()['id'];
        $this->signIn();

        $counts = function () use ($id) {
            $c = $this->getJson("/v1/events/{$id}")->assertOk()->json('counts');

            return [$c['going'], $c['maybe'], $c['declined']];
        };

        $this->putJson("/v1/events/{$id}/rsvp", ['rsvp' => 'maybe'])->assertOk();
        $this->assertSame([1, 1, 0], $counts());

        $this->putJson("/v1/events/{$id}/rsvp", ['rsvp' => 'going'])->assertOk();
        $this->assertSame([2, 0, 0], $counts());

        $this->putJson("/v1/events/{$id}/rsvp", ['rsvp' => 'declined'])->assertOk();
        $this->assertSame([1, 0, 1], $counts());

        $this->putJson("/v1/events/{$id}/rsvp", ['rsvp' => 'declined'])->assertOk();
        $this->assertSame([1, 0, 1], $counts(), 'repeating an answer changes nothing');
    }

    public function test_my_events_says_how_i_answered(): void
    {
        $this->signIn();
        $id = $this->createEvent()['id'];
        $this->signIn();
        $this->putJson("/v1/events/{$id}/rsvp", ['rsvp' => 'maybe'])->assertOk();

        $this->getJson('/v1/me/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.my_rsvp', 'maybe')
            ->assertJsonPath('data.0.is_host', false);
    }

    public function test_the_city_can_be_edited(): void
    {
        $this->signIn();
        $id = $this->createEvent()['id'];

        $this->patchJson("/v1/events/{$id}", ['venue_city' => 'Accra'])
            ->assertOk()->assertJsonPath('venue.city', 'Accra');
    }
}

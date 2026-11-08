<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The read side of the feed works signed out, and personalises with a token. */
class FeedTest extends TestCase
{
    use RefreshDatabase;

    private function publish(User $author, array $overrides = []): string
    {
        Sanctum::actingAs($author, ['access']);

        return $this->postJson('/v1/posts', array_merge([
            'caption' => 'Our setup for Saturday',
            'visibility' => 'public',
            'media' => [['url' => 'https://example.com/a.jpg', 'type' => 'image', 'w' => 1080, 'h' => 1350]],
        ], $overrides))->assertCreated()->json('id');
    }

    /**
     * Forget the acting user, so the next request is anonymous. actingAs()
     * also makes `sanctum` the default guard; put back the real default.
     */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    public function test_discover_works_signed_out(): void
    {
        $id = $this->publish(User::factory()->create());
        $this->signOut();

        $this->getJson('/v1/feed/discover')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.my_reaction', null);
    }

    public function test_discover_shows_my_like_when_signed_in(): void
    {
        $id = $this->publish(User::factory()->create());
        $viewer = $this->signIn();
        $this->putJson("/v1/posts/{$id}/reaction")->assertOk();

        $this->withToken($viewer->createToken('phone', ['access'])->plainTextToken);
        $this->signOut();
        $this->getJson('/v1/feed/discover')->assertOk()->assertJsonPath('data.0.my_reaction', 'like');
    }

    public function test_a_refresh_token_does_not_personalise(): void
    {
        $id = $this->publish(User::factory()->create());
        $viewer = $this->signIn();
        $this->putJson("/v1/posts/{$id}/reaction")->assertOk();
        $this->signOut();

        $this->withToken($viewer->createToken('phone:refresh', ['refresh'])->plainTextToken)
            ->getJson('/v1/feed/discover')
            ->assertOk()
            ->assertJsonPath('data.0.my_reaction', null);
    }

    public function test_a_post_and_its_comments_are_readable_signed_out(): void
    {
        $id = $this->publish(User::factory()->create());
        $this->postJson("/v1/posts/{$id}/comments", ['body' => 'Gorgeous!'])->assertCreated();
        $this->signOut();

        $this->getJson("/v1/posts/{$id}")->assertOk()->assertJsonPath('id', $id);
        $this->getJson("/v1/posts/{$id}/comments")->assertOk()->assertJsonPath('data.0.body', 'Gorgeous!');
    }

    public function test_following_and_writing_still_need_sign_in(): void
    {
        $id = $this->publish(User::factory()->create());
        $this->signOut();

        $this->getJson('/v1/feed/following')->assertUnauthorized();
        $this->putJson("/v1/posts/{$id}/reaction")->assertUnauthorized();
        $this->postJson("/v1/posts/{$id}/comments", ['body' => 'hi'])->assertUnauthorized();
    }

    public function test_comments_on_an_event_only_post_stay_with_its_members(): void
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host, ['access']);
        $eventId = $this->postJson('/v1/events', [
            'type' => 'wedding', 'title' => 'Private do', 'visibility' => 'private',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHours(5)->toIso8601String(),
        ])->assertCreated()->json('id');
        $id = $this->publish($host, ['event_id' => $eventId, 'visibility' => 'event_members']);

        $this->signIn(); // not a member
        $this->getJson("/v1/posts/{$id}/comments")->assertForbidden();

        $this->signOut();
        $this->getJson("/v1/posts/{$id}/comments")->assertForbidden();
        $this->getJson("/v1/posts/{$id}")->assertForbidden();
    }
}

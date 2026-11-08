<?php

namespace Tests\Feature;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Notifications\PostLiked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SocialTest extends TestCase
{
    use RefreshDatabase;

    private function photo(): array
    {
        return ['url' => 'https://cdn.example.com/p/1.jpg', 'type' => 'image', 'w' => 1080, 'h' => 1350, 'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj'];
    }

    public function test_following_puts_posts_in_my_feed(): void
    {
        $ama = User::factory()->create();
        $kofi = User::factory()->create();

        $this->signIn($kofi);
        $this->postJson('/v1/follows', ['target_type' => 'user', 'target_id' => $ama->id])->assertCreated()->assertJson(['followers' => 1]);

        $this->signIn($ama);
        $postId = $this->postJson('/v1/posts', ['caption' => 'Setup day #rusticwedding', 'media' => [$this->photo()]])
            ->assertCreated()->assertJsonPath('hashtags.0', 'rusticwedding')->json('id');

        $this->signIn($kofi);
        $this->getJson('/v1/feed/following')->assertOk()->assertJsonPath('data.0.id', $postId);

        $this->deleteJson('/v1/follows', ['target_type' => 'user', 'target_id' => $ama->id])->assertOk();
        $this->getJson('/v1/feed/following')->assertJsonCount(0, 'data');
    }

    public function test_tagging_another_vendors_item_needs_their_approval(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        $chairs = $this->variant($vendor);
        $host = User::factory()->create();
        $event = Event::factory()->create(['host_id' => $host->id]);

        $this->signIn($host);
        $post = $this->postJson('/v1/posts', [
            'event_id' => $event->id,
            'media' => [$this->photo()],
            'tags' => [['media_index' => 0, 'x' => 0.4, 'y' => 0.7, 'target_type' => 'item', 'target_id' => $chairs->itemId]],
        ])->assertCreated()->json();

        // Pending tags are hidden from other viewers.
        $this->signIn(User::factory()->create());
        $this->getJson("/v1/posts/{$post['id']}")->assertJsonCount(0, 'media.0.tags');

        // The vendor approves; now everyone sees the "Rent this" pin with the item card.
        $this->signIn($owner);
        $tagId = $this->getJson("/v1/vendors/{$vendor->id}/post-tags")->assertJsonCount(1, 'data')->json('data.0.id');
        $this->postJson("/v1/vendors/{$vendor->id}/post-tags/{$tagId}", ['status' => 'approved'])->assertOk();

        $this->signIn(User::factory()->create());
        $this->getJson("/v1/posts/{$post['id']}")
            ->assertJsonPath('media.0.tags.0.target.item.title', 'Chiavari chair'); // the item, not the variant
        $this->getJson("/v1/items/{$chairs->itemId}/posts")->assertJsonPath('data.0.id', $post['id']);
    }

    public function test_vendors_post_as_the_business(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();

        $this->signIn($owner);
        $this->postJson('/v1/posts', ['as_vendor_id' => $vendor->id, 'media' => [$this->photo()]])
            ->assertCreated()
            ->assertJsonPath('author.type', 'vendor')
            ->assertJsonPath('kind', 'showcase');

        $this->signIn(User::factory()->create());
        $this->postJson('/v1/posts', ['as_vendor_id' => $vendor->id, 'media' => [$this->photo()]])->assertForbidden();
    }

    public function test_likes_are_counted_once_and_notify_the_author(): void
    {
        $author = $this->signIn();
        $postId = $this->postJson('/v1/posts', ['media' => [$this->photo()]])->json('id');

        $this->signIn();
        $this->putJson("/v1/posts/{$postId}/reaction")->assertOk()->assertJson(['likes' => 1]);
        $this->putJson("/v1/posts/{$postId}/reaction", ['kind' => 'fire'])->assertJson(['likes' => 1, 'my_reaction' => 'fire']);
        $this->getJson("/v1/posts/{$postId}")->assertJsonPath('my_reaction', 'fire');

        $this->assertSame(1, $author->notifications()->where('type', PostLiked::class)->count());

        $this->deleteJson("/v1/posts/{$postId}/reaction")->assertJson(['likes' => 0]);
    }

    public function test_comments_have_one_level_of_replies(): void
    {
        $this->signIn();
        $postId = $this->postJson('/v1/posts', ['media' => [$this->photo()]])->json('id');

        $top = $this->postJson("/v1/posts/{$postId}/comments", ['body' => 'Gorgeous!'])->assertCreated()->json('id');
        $reply = $this->postJson("/v1/posts/{$postId}/comments", ['body' => 'Thank you', 'parent_id' => $top])->json();
        $nested = $this->postJson("/v1/posts/{$postId}/comments", ['body' => 'Agreed', 'parent_id' => $reply['id']])->json();

        $this->assertSame($top, $nested['parent_id']);
        $this->assertSame(3, Post::find($postId)->comments_count);
        $this->getJson("/v1/posts/{$postId}/comments")->assertJsonCount(1, 'data')->assertJsonPath('data.0.replies', 2);
    }

    public function test_discover_ranks_engaging_posts_first(): void
    {
        $this->signIn();
        $quiet = $this->postJson('/v1/posts', ['media' => [$this->photo()]])->json('id');
        $popular = $this->postJson('/v1/posts', ['media' => [$this->photo()]])->json('id');
        Post::whereKey($popular)->update(['likes_count' => 50, 'shop_taps_count' => 10]);

        Artisan::call('posts:rescore');

        $this->getJson('/v1/feed/discover')->assertJsonPath('data.0.id', $popular)->assertJsonPath('data.1.id', $quiet);
    }
}

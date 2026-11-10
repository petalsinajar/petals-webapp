<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaProcessor;
use App\Modules\Media\Video\StreamWebhook;
use App\Modules\Social\Models\Post;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Using uploads in posts, items, profiles and events; and cleaning up the ones not used. */
class MediaAttachmentTest extends TestCase
{
    use RefreshDatabase;

    /** A processed image whose renditions really are in (fake) storage. */
    private function storedImage(User $owner, ?Vendor $vendor = null): Media
    {
        $media = $this->media($owner, vendor: $vendor);
        $keys = ['thumb' => "media/{$media->id}/thumb.webp", 'feed' => "media/{$media->id}/feed.webp", 'full' => "media/{$media->id}/full.webp"];
        foreach ($keys as $key) {
            $this->mediaStorage()->put($key, 'webp', 'image/webp');
        }
        $media->update(['variants' => $keys]);

        return $media;
    }

    public function test_a_post_can_only_use_the_posters_own_uploads(): void
    {
        $someoneElse = $this->media(User::factory()->create());
        $this->signIn();

        $this->postJson('/v1/posts', ['media' => [$someoneElse->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0' => 'Upload not found.']);
        $this->assertNull($someoneElse->refresh()->attached_at);
    }

    public function test_a_post_returns_media_objects_and_marks_them_attached(): void
    {
        $user = $this->signIn();
        $photo = $this->media($user);
        $clip = $this->media($user, 'video');

        $this->postJson('/v1/posts', ['media' => [$clip->id, $photo->id]])
            ->assertCreated()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('media.0.kind', 'video')
            ->assertJsonPath('media.0.urls.hls', 'https://stream.test/v/manifest/video.m3u8')
            ->assertJsonPath('media.1.id', $photo->id)
            ->assertJsonPath('media.1.urls', ['thumb' => 'https://cdn.test/media/t/thumb.webp', 'feed' => 'https://cdn.test/media/t/feed.webp', 'full' => 'https://cdn.test/media/t/full.webp'])
            ->assertJsonPath('media.1.blurhash', 'LEHV6nWB2yk8pyo0adR*.7kCMdnj');

        $this->assertNotNull($photo->refresh()->attached_at);
        $this->assertSame(['post', 1], [$photo->attachable_type, $photo->position]);
    }

    public function test_an_upload_can_only_be_used_once(): void
    {
        $user = $this->signIn();
        $photo = $this->media($user);
        $this->postJson('/v1/posts', ['media' => [$photo->id]])->assertCreated();

        $this->postJson('/v1/posts', ['media' => [$photo->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0' => 'This upload is already used somewhere else.']);
    }

    public function test_a_post_with_processing_media_publishes_once_it_is_ready(): void
    {
        $user = $this->signIn();
        $follower = User::factory()->create();
        $this->signIn($follower);
        $this->postJson('/v1/follows', ['target_type' => 'user', 'target_id' => $user->id])->assertCreated();

        $this->signIn($user);
        $ready = $this->media($user);
        $video = $this->media($user, 'video', MediaStatus::Processing);
        $video->update(['provider_ref' => 'vid-1']);
        $this->videoProvider()->videos['vid-1'] = ['uid' => 'vid-1', 'status' => ['state' => 'inprogress']];

        $postId = $this->postJson('/v1/posts', ['media' => [$ready->id, $video->id]])
            ->assertCreated()->assertJsonPath('status', 'processing')->json('id');

        $this->assertSame(0, $user->refresh()->posts_count);
        $this->signIn($follower);
        $this->getJson("/v1/posts/{$postId}")->assertNotFound();
        $this->getJson('/v1/feed/following')->assertJsonCount(0, 'data');

        // Stream finishes transcoding.
        $payload = $this->videoProvider()->simulateReady('vid-1');
        $body = json_encode($payload);
        $this->call('POST', '/v1/webhooks/video', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => StreamWebhook::sign($body, 'test-webhook-secret'),
        ], $body)->assertOk();

        $this->assertSame('published', Post::find($postId)->status);
        $this->assertSame(1, $user->refresh()->posts_count);
        $this->getJson("/v1/posts/{$postId}")->assertOk()->assertJsonPath('media.1.status', 'ready');
        $this->getJson('/v1/feed/following')->assertJsonCount(1, 'data');
    }

    public function test_a_post_whose_media_fails_never_publishes(): void
    {
        $user = $this->signIn();
        $video = $this->media($user, 'video', MediaStatus::Uploaded);
        $video->update(['provider_ref' => 'vid-2']);
        $this->videoProvider()->videos['vid-2'] = ['uid' => 'vid-2', 'status' => ['state' => 'inprogress']];
        $postId = $this->postJson('/v1/posts', ['media' => [$video->id]])->assertCreated()->json('id');

        app(MediaProcessor::class)->videoUpdated(
            StreamWebhook::status($this->videoProvider()->simulateError('vid-2')));

        $this->assertSame('failed', Post::find($postId)->status);
    }

    public function test_pending_uploads_are_refused_and_items_need_ready_media(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        Category::create(['id' => 'chairs', 'name' => 'Chairs']);
        $this->signIn($owner);

        $pending = $this->media($owner, status: MediaStatus::Pending);
        $processing = $this->media($owner, status: MediaStatus::Processing);
        $payload = fn (array $media) => [
            'category_id' => 'chairs', 'title' => 'Chair', 'media' => $media,
            'pricing' => ['unit' => 'per_event', 'tiers' => [['min_qty' => 1, 'price_minor' => 100]]],
            'variants' => [['name' => 'Gold', 'quantity' => 1]],
        ];

        $this->postJson('/v1/posts', ['media' => [$pending->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0' => 'This upload hasn\'t been completed.']);
        $this->postJson("/v1/vendors/{$vendor->id}/items", $payload([$processing->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['media.0' => 'This upload is still processing.']);
        $this->postJson("/v1/vendors/{$vendor->id}/items", $payload([$this->media($owner)->id]))->assertCreated();
    }

    public function test_profiles_take_images_only_and_replacing_one_deletes_the_old(): void
    {
        $user = $this->signIn();
        $first = $this->storedImage($user);
        $second = $this->storedImage($user);

        $this->patchJson('/v1/me', ['avatar_media_id' => $this->media($user, 'video')->id])
            ->assertStatus(422)->assertJsonValidationErrors(['avatar_media_id' => 'A video can\'t be used here.']);
        $this->patchJson('/v1/me', ['avatar_media_id' => $this->media(User::factory()->create())->id])
            ->assertStatus(422)->assertJsonValidationErrors('avatar_media_id');

        $this->patchJson('/v1/me', ['avatar_media_id' => $first->id])
            ->assertOk()
            ->assertJsonPath('avatar.id', $first->id)
            ->assertJsonPath('avatar_url', "https://cdn.test/media/{$first->id}/thumb.webp");

        $this->patchJson('/v1/me', ['avatar_media_id' => $second->id])->assertOk()->assertJsonPath('avatar.id', $second->id);
        $this->assertModelMissing($first);
        $this->assertFalse($this->mediaStorage()->exists("media/{$first->id}/thumb.webp"));

        $this->patchJson('/v1/me', ['avatar_media_id' => null])->assertOk()->assertJsonPath('avatar', null);
        $this->assertModelMissing($second);
    }

    public function test_vendor_logo_and_cover_accept_uploads_made_for_the_business(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        $staff = User::factory()->create();
        $vendor->members()->create(['user_id' => $staff->id, 'role' => 'staff', 'status' => 'active']);
        $logo = $this->media($staff, vendor: $vendor);
        $stranger = $this->media(User::factory()->create(), vendor: Vendor::factory()->create());

        $this->signIn($owner);
        $this->patchJson("/v1/vendors/{$vendor->id}", ['cover_media_id' => $stranger->id])
            ->assertStatus(422)->assertJsonValidationErrors('cover_media_id');
        $this->patchJson("/v1/vendors/{$vendor->id}", ['logo_media_id' => $logo->id, 'cover_media_id' => $this->media($owner)->id])
            ->assertOk()
            ->assertJsonPath('logo.id', $logo->id)
            ->assertJsonPath('logo_url', 'https://cdn.test/media/t/thumb.webp')
            ->assertJsonPath('cover_url', 'https://cdn.test/media/t/feed.webp');

        // Changing only the cover keeps the logo.
        $this->patchJson("/v1/vendors/{$vendor->id}", ['cover_media_id' => $this->media($owner)->id])->assertOk()->assertJsonPath('logo.id', $logo->id);
        $this->assertModelExists($logo);
    }

    public function test_event_covers_are_the_hosts_images(): void
    {
        $host = $this->signIn();
        $cover = $this->media($host);
        $event = [
            'type' => 'wedding', 'title' => 'Our day', 'starts_at' => now()->addMonth()->toIso8601String(),
            'ends_at' => now()->addMonth()->addHours(6)->toIso8601String(),
        ];

        $this->postJson('/v1/events', [...$event, 'cover_media_id' => $this->media(User::factory()->create())->id])
            ->assertStatus(422)->assertJsonValidationErrors('cover_media_id');

        $id = $this->postJson('/v1/events', [...$event, 'cover_media_id' => $cover->id])
            ->assertCreated()
            ->assertJsonPath('cover.id', $cover->id)
            ->assertJsonPath('cover_url', 'https://cdn.test/media/t/feed.webp')
            ->json('id');
        $this->assertSame(['event', $id], [$cover->refresh()->attachable_type, $cover->attachable_id]);
    }

    public function test_deleting_a_post_deletes_its_files(): void
    {
        $user = $this->signIn();
        $photo = $this->storedImage($user);
        $postId = $this->postJson('/v1/posts', ['media' => [$photo->id]])->assertCreated()->json('id');

        $this->deleteJson("/v1/posts/{$postId}")->assertOk();

        $this->assertModelMissing($photo);
        $this->assertSame([], $this->mediaStorage()->objects);
        $this->assertSame(0, $user->refresh()->posts_count);
    }

    public function test_archiving_an_item_deletes_its_photos(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        $chair = $this->variant($vendor);
        $photo = $this->storedImage($owner);
        $this->signIn($owner);
        $this->patchJson("/v1/vendors/{$vendor->id}/items/{$chair->itemId}", ['media' => [$photo->id]])->assertOk();

        $this->deleteJson("/v1/vendors/{$vendor->id}/items/{$chair->itemId}")->assertOk();

        $this->assertModelMissing($photo);
        $this->assertSame([], $this->mediaStorage()->objects);
    }

    public function test_the_daily_cleanup_deletes_uploads_never_used_after_a_day(): void
    {
        $user = User::factory()->create();
        $abandoned = $this->storedImage($user);
        $recent = $this->storedImage($user);
        $used = $this->storedImage($user);
        $video = $this->media($user, 'video', MediaStatus::Pending);
        $video->update(['provider_ref' => 'vid-old']);
        $this->signIn($user);
        $this->postJson('/v1/posts', ['media' => [$used->id]])->assertCreated();

        Media::whereKey([$abandoned->id, $used->id, $video->id])->update(['created_at' => now()->subHours(25)]);

        Artisan::call('media:prune');

        $this->assertModelMissing($abandoned);
        $this->assertModelMissing($video);
        $this->assertContains('vid-old', $this->videoProvider()->deleted);
        $this->assertModelExists($recent);
        $this->assertModelExists($used);
        $this->assertFalse($this->mediaStorage()->exists("media/{$abandoned->id}/feed.webp"));
        $this->assertTrue($this->mediaStorage()->exists("media/{$used->id}/feed.webp"));
    }

    public function test_the_cleanup_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'media:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}

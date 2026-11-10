<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Contracts\ContentModeration;
use App\Modules\Media\Data\ModerationVerdict;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Video\StreamWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UploadsTest extends TestCase
{
    use RefreshDatabase;

    /** A landscape JPEG whose EXIF says "rotate 90°" and carries a GPS position. */
    public static function phoneJpeg(int $width = 3000, int $height = 2000): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, intdiv($width, 2), $height, imagecolorallocate($gd, 200, 80, 120));
        imagefilledrectangle($gd, intdiv($width, 2), 0, $width, $height, imagecolorallocate($gd, 40, 160, 90));
        ob_start();
        imagejpeg($gd, null, 85);
        $jpeg = ob_get_clean();

        // Minimal little-endian TIFF: IFD0 { Orientation = 6, GPSInfo → } GPS IFD { LatitudeRef "N", Latitude 5/1 36/1 13/1 }
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");
        $tiff = "II*\0".pack('V', 8)
            .pack('v', 2).$entry(0x0112, 3, 1, pack('v', 6)).$entry(0x8825, 4, 1, pack('V', 38)).pack('V', 0)
            .pack('v', 2).$entry(0x0001, 2, 2, "N\0").$entry(0x0002, 5, 3, pack('V', 68)).pack('V', 0)
            .pack('V*', 5, 1, 36, 1, 13, 1);
        $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\0\0".$tiff;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function requestUpload(array $data = []): array
    {
        return $this->postJson('/v1/uploads', [
            'kind' => 'image', 'mime' => 'image/jpeg', 'bytes' => 1000, 'purpose' => 'post', ...$data,
        ])->assertCreated()->json();
    }

    /** Request an image upload and put $contents where the signed URL points. */
    private function uploaded(string $contents, array $data = []): Media
    {
        $upload = $this->requestUpload(['bytes' => strlen($contents), ...$data]);
        $media = Media::find($upload['media_id']);
        $this->mediaStorage()->simulateUpload($media->storage_key, $contents, $media->mime);

        return $media;
    }

    // ---- Requesting an upload ---------------------------------------------

    public function test_an_image_upload_gets_a_signed_put_locked_to_its_type_and_size(): void
    {
        $this->freezeTime();
        $user = $this->signIn();

        $response = $this->requestUpload(['bytes' => 245_000]);

        $this->assertSame('pending', $response['status']);
        $this->assertSame('PUT', $response['upload']['method']);
        $this->assertStringStartsWith('https://uploads.test/uploads/', $response['upload']['url']);
        $this->assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '245000'], $response['upload']['headers']);
        $this->assertSame(now()->addMinutes(10)->toIso8601String(), $response['upload']['expires_at']);

        $media = Media::findOrFail($response['media_id']);
        $this->assertSame($user->id, $media->owner_id);
        $this->assertNotNull($media->storage_key);
        $this->assertNull($media->attached_at);
    }

    public function test_upload_requests_are_checked_against_the_limits(): void
    {
        $this->signIn();
        $ask = fn (array $data) => $this->postJson('/v1/uploads', ['kind' => 'image', 'mime' => 'image/jpeg', 'bytes' => 1000, 'purpose' => 'post', ...$data]);

        $ask(['mime' => 'image/gif'])->assertStatus(422)->assertJsonValidationErrors('mime');
        $ask(['mime' => 'application/pdf'])->assertStatus(422)->assertJsonValidationErrors('mime');
        $ask(['bytes' => 15 * 1024 * 1024 + 1])->assertStatus(422)->assertJsonValidationErrors('bytes');
        $ask(['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 200 * 1024 * 1024 + 1])->assertStatus(422)->assertJsonValidationErrors('bytes');
        $ask(['kind' => 'video', 'mime' => 'video/mp4', 'duration_ms' => 181_000])->assertStatus(422)->assertJsonValidationErrors('duration_ms');
        $ask(['kind' => 'video', 'mime' => 'video/mp4', 'purpose' => 'avatar'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $ask(['purpose' => 'wallpaper'])->assertStatus(422)->assertJsonValidationErrors('purpose');

        foreach (['image/png', 'image/heic', 'image/webp'] as $mime) {
            $ask(['mime' => $mime])->assertCreated();
        }
        $ask(['kind' => 'video', 'mime' => 'video/quicktime', 'duration_ms' => 180_000])->assertCreated();
    }

    public function test_uploading_for_a_vendor_needs_to_be_on_its_team(): void
    {
        $vendor = Vendor::factory()->ownedBy()->create();
        $this->signIn();

        $this->postJson('/v1/uploads', ['kind' => 'image', 'mime' => 'image/jpeg', 'bytes' => 10, 'purpose' => 'item', 'vendor_id' => $vendor->id])
            ->assertForbidden();
    }

    public function test_uploads_are_rate_limited_per_user(): void
    {
        $this->signIn();
        for ($i = 0; $i < 20; $i++) {
            $this->requestUpload();
        }

        $this->postJson('/v1/uploads', ['kind' => 'image', 'mime' => 'image/jpeg', 'bytes' => 10, 'purpose' => 'post'])->assertStatus(429);

        $this->signIn(); // someone else isn't affected
        $this->requestUpload();
    }

    public function test_uploads_need_a_signed_in_user(): void
    {
        $this->postJson('/v1/uploads', ['kind' => 'image', 'mime' => 'image/jpeg', 'bytes' => 10, 'purpose' => 'post'])->assertUnauthorized();
    }

    // ---- Completing and processing ------------------------------------------

    public function test_complete_processes_the_image_into_stripped_upright_webp_renditions(): void
    {
        $this->signIn();
        $jpeg = self::phoneJpeg();
        $this->assertArrayHasKey('GPSLatitude', exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg)), 'the fixture carries GPS');
        $media = $this->uploaded($jpeg);
        $original = $media->storage_key;

        // The queue runs inline in tests: complete → ProcessMedia → ready.
        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk()->assertJsonPath('status', 'ready');

        $media->refresh();
        $this->assertSame([2000, 3000], [$media->width, $media->height], 'rotated upright from the EXIF orientation');
        $this->assertNotEmpty($media->blurhash);
        $this->assertFalse($this->mediaStorage()->exists($original), 'the original (with its GPS) is deleted');

        $expected = ['thumb' => [213, 320], 'feed' => [720, 1080], 'full' => [1365, 2048]];
        foreach ($expected as $name => [$w, $h]) {
            $bytes = $this->mediaStorage()->get("media/{$media->id}/{$name}.webp");
            [$width, $height, $type] = getimagesizefromstring($bytes);
            $this->assertSame(IMAGETYPE_WEBP, $type, "$name is WebP");
            $this->assertEqualsWithDelta($w, $width, 1, "$name width");
            $this->assertSame($h, $height, "$name height");
            $this->assertStringNotContainsString('Exif', $bytes, "$name has no EXIF");
            $this->assertStringNotContainsString('GPS', $bytes, "$name has no GPS");
        }

        $this->getJson("/v1/media/{$media->id}")
            ->assertJsonPath('urls.feed', "https://cdn.test/media/{$media->id}/feed.webp")
            ->assertJsonPath('width', 2000);

        // Completing again changes nothing.
        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_complete_rejects_a_file_whose_size_does_not_match(): void
    {
        $this->signIn();
        $jpeg = self::phoneJpeg(400, 300);
        $upload = $this->requestUpload(['bytes' => strlen($jpeg) + 10]);
        $media = Media::find($upload['media_id']);
        $this->mediaStorage()->simulateUpload($media->storage_key, $jpeg, 'image/jpeg');

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertStatus(422)->assertJsonValidationErrors('upload');

        $media->refresh();
        $this->assertSame('failed', $media->status->value);
        $this->assertSame('size_mismatch', $media->failure_reason);
        $this->assertSame([], $this->mediaStorage()->objects, 'the file is deleted');
    }

    public function test_complete_rejects_a_file_that_is_not_the_declared_type(): void
    {
        $this->signIn();
        // Declared (and signed) as PNG, but the bytes are a JPEG.
        $media = $this->uploaded(self::phoneJpeg(400, 300), ['mime' => 'image/png']);

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertStatus(422);
        $this->assertSame('type_mismatch', $media->refresh()->failure_reason);
        $this->assertSame([], $this->mediaStorage()->objects);
    }

    public function test_complete_before_the_file_arrives_keeps_it_pending(): void
    {
        $this->signIn();
        $upload = $this->requestUpload();

        $this->postJson("/v1/uploads/{$upload['media_id']}/complete")->assertStatus(422)->assertJsonValidationErrors('upload');
        $this->assertSame('pending', Media::find($upload['media_id'])->status->value);
    }

    public function test_only_the_uploader_can_complete_or_see_an_upload(): void
    {
        $owner = $this->signIn();
        $upload = $this->requestUpload();

        $this->signIn();
        $this->postJson("/v1/uploads/{$upload['media_id']}/complete")->assertForbidden();
        $this->getJson("/v1/media/{$upload['media_id']}")->assertNotFound();

        $this->signIn($owner);
        $this->getJson("/v1/media/{$upload['media_id']}")->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_large_images_upload_in_parts(): void
    {
        // Same rule as the 8 MB / 5 MB defaults, at a size that keeps the test light.
        config(['eventapp.media.multipart_threshold_bytes' => 200_000, 'eventapp.media.multipart_part_bytes' => 150_000]);
        $this->signIn();
        $bytes = 250_000; // over the threshold: a 150 KB part and a 100 KB part
        $upload = $this->requestUpload(['bytes' => $bytes]);

        $this->assertSame('multipart', $upload['upload']['method']);
        $this->assertSame([1, 2], array_column($upload['upload']['parts'], 'part_number'));
        $this->assertSame([150_000, 100_000], array_column($upload['upload']['parts'], 'bytes'));

        $media = Media::find($upload['media_id']);
        $file = str_pad(self::phoneJpeg(400, 300), $bytes, "\0"); // a JPEG with padding after its end marker
        $etags = [];
        foreach ($upload['upload']['parts'] as $part) {
            $chunk = substr($file, ($part['part_number'] - 1) * 150_000, $part['bytes']);
            $etags[] = ['part_number' => $part['part_number'], 'etag' => $this->mediaStorage()->simulatePart($media->multipart_upload_id, $part['part_number'], $chunk)];
        }

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->postJson("/v1/uploads/{$media->id}/complete", ['parts' => [['part_number' => 1, 'etag' => '"nope"'], $etags[1]]])
            ->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->postJson("/v1/uploads/{$media->id}/complete", ['parts' => $etags])->assertOk();

        $this->assertSame('ready', $media->refresh()->status->value);
    }

    public function test_moderation_runs_before_ready_and_can_reject(): void
    {
        $this->app->instance(ContentModeration::class, new class implements ContentModeration
        {
            public function review(Media $media): ModerationVerdict
            {
                return ModerationVerdict::reject('nudity');
            }
        });

        $this->signIn();
        $media = $this->uploaded(self::phoneJpeg(400, 300));

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk();

        $media->refresh();
        $this->assertSame(['rejected', 'nudity'], [$media->status->value, $media->failure_reason]);
        $this->assertSame([], $this->mediaStorage()->objects, 'nothing is left in storage');
        $this->assertNull($media->toPayload()['urls']);
    }

    public function test_an_unreadable_image_fails_instead_of_retrying(): void
    {
        $this->signIn();
        $media = $this->uploaded("\xFF\xD8\xFF".str_repeat('x', 500)); // starts like a JPEG, isn't one

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk();

        $this->assertSame(['failed', 'unreadable_image'], [$media->refresh()->status->value, $media->failure_reason]);
    }

    // ---- Video ------------------------------------------------------------

    private function signedWebhook(array $payload, ?int $time = null, string $secret = 'test-webhook-secret'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/v1/webhooks/video', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => StreamWebhook::sign($body, $secret, $time),
        ], $body);
    }

    public function test_video_goes_to_the_provider_and_is_ready_after_its_webhook(): void
    {
        $this->signIn();
        $upload = $this->requestUpload(['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 8_000_000]);

        $this->assertSame('POST', $upload['upload']['method']);
        $this->assertSame('file', $upload['upload']['field']);
        $this->assertStringStartsWith('https://upload.stream.test/', $upload['upload']['url']);

        $media = Media::find($upload['media_id']);
        $this->assertSame(180, $this->videoProvider()->videos[$media->provider_ref]['maxDurationSeconds']);

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertStatus(422); // nothing uploaded yet
        $this->videoProvider()->simulateUpload($media->provider_ref, 8_000_000);
        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk()->assertJsonPath('status', 'processing');

        $payload = $this->videoProvider()->simulateReady($media->provider_ref, seconds: 42.4);
        $this->signedWebhook($payload)->assertOk();
        $this->signedWebhook($payload)->assertOk(); // a repeated webhook changes nothing

        $this->getJson("/v1/media/{$media->id}")
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('duration_ms', 42400)
            ->assertJsonPath('urls.hls', "https://stream.test/{$media->provider_ref}/manifest/video.m3u8")
            ->assertJsonPath('urls.poster', "https://stream.test/{$media->provider_ref}/thumbnails/thumbnail.jpg");
    }

    public function test_video_size_must_match_what_was_declared(): void
    {
        $this->signIn();
        $upload = $this->requestUpload(['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 8_000_000]);
        $media = Media::find($upload['media_id']);
        $this->videoProvider()->simulateUpload($media->provider_ref, 9_500_000);

        $this->postJson("/v1/uploads/{$media->id}/complete")->assertStatus(422);

        $this->assertSame('size_mismatch', $media->refresh()->failure_reason);
        $this->assertContains($media->provider_ref, $this->videoProvider()->deleted);
    }

    public function test_video_webhooks_need_a_valid_fresh_signature(): void
    {
        $this->signIn();
        $upload = $this->requestUpload(['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 100]);
        $media = Media::find($upload['media_id']);
        $this->videoProvider()->simulateUpload($media->provider_ref, 100);
        $payload = $this->videoProvider()->simulateReady($media->provider_ref);

        $this->signedWebhook($payload, secret: 'wrong-secret')->assertUnauthorized();
        $this->signedWebhook($payload, time: time() - 3600)->assertUnauthorized();
        $this->postJson('/v1/webhooks/video', $payload)->assertUnauthorized();
        $this->assertSame('pending', $media->refresh()->status->value);

        $this->signedWebhook($payload)->assertOk();
        $this->assertSame('ready', $media->refresh()->status->value);
    }

    public function test_a_failed_video_webhook_fails_the_media(): void
    {
        $this->signIn();
        $upload = $this->requestUpload(['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 100]);
        $media = Media::find($upload['media_id']);
        $this->videoProvider()->simulateUpload($media->provider_ref, 100);
        $this->postJson("/v1/uploads/{$media->id}/complete")->assertOk();

        $this->signedWebhook($this->videoProvider()->simulateError($media->provider_ref, 'ERR_DURATION_EXCEED_CONSTRAINT'))->assertOk();

        $this->assertSame(['failed', 'ERR_DURATION_EXCEED_CONSTRAINT'], [$media->refresh()->status->value, $media->failure_reason]);
    }

    public function test_vendor_team_members_can_poll_uploads_made_for_the_vendor(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        $staff = User::factory()->create();
        $vendor->members()->create(['user_id' => $staff->id, 'role' => 'staff', 'status' => 'active']);

        $media = $this->media($staff, vendor: $vendor);

        $this->signIn($owner);
        $this->getJson("/v1/media/{$media->id}")->assertOk()->assertJsonPath('urls.thumb', 'https://cdn.test/media/t/thumb.webp');
    }
}

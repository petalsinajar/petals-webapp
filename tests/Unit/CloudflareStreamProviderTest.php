<?php

namespace Tests\Unit;

use App\Modules\Media\Data\VideoStatus;
use App\Modules\Media\Services\MimeSniffer;
use App\Modules\Media\Video\CloudflareStreamProvider;
use App\Modules\Media\Video\StreamWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareStreamProviderTest extends TestCase
{
    private function provider(): CloudflareStreamProvider
    {
        return new CloudflareStreamProvider([
            'account_id' => 'acc123',
            'api_token' => 'tok',
            'webhook_secret' => 'whsec',
            'delivery_url' => 'https://customer-abc.cloudflarestream.com',
            'webhook_tolerance_seconds' => 300,
        ]);
    }

    public function test_it_asks_stream_for_a_direct_creator_upload(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['uid' => 'v123', 'uploadURL' => 'https://upload.videodelivery.net/abc']])]);

        [$ref, $upload] = $this->provider()->createUpload('01media', 180, CarbonImmutable::parse('2026-11-10 12:10:00', 'UTC'));

        $this->assertSame('v123', $ref);
        $this->assertSame(['POST', 'https://upload.videodelivery.net/abc', 'file'], [$upload->method, $upload->url, $upload->field]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.cloudflare.com/client/v4/accounts/acc123/stream/direct_upload'
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['maxDurationSeconds'] === 180
            && $r['expiry'] === '2026-11-10T12:10:00Z'
            && $r['meta'] === ['media_id' => '01media']);
    }

    public function test_stream_states_map_to_video_statuses(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['result' => ['uid' => 'v1', 'status' => ['state' => 'pendingupload']]])
            ->push(['result' => ['uid' => 'v1', 'size' => 5000, 'duration' => -1, 'status' => ['state' => 'inprogress']]])
            ->push(['result' => ['uid' => 'v1', 'readyToStream' => true, 'size' => 5000, 'duration' => 12.25, 'input' => ['width' => 720, 'height' => 1280], 'status' => ['state' => 'ready']]])]);

        $this->assertSame(VideoStatus::PENDING_UPLOAD, $this->provider()->status('v1')->state);

        $processing = $this->provider()->status('v1');
        $this->assertSame([VideoStatus::PROCESSING, 5000, null], [$processing->state, $processing->bytes, $processing->durationMs]);

        $ready = $this->provider()->status('v1');
        $this->assertTrue($ready->isReady());
        $this->assertSame([12250, 720, 1280], [$ready->durationMs, $ready->width, $ready->height]);
        $this->assertSame('https://customer-abc.cloudflarestream.com/v1/manifest/video.m3u8', $this->provider()->playbackUrl($ready->hlsPath));
    }

    public function test_webhook_signatures_are_checked(): void
    {
        $body = json_encode(['uid' => 'v1', 'status' => ['state' => 'ready']]);
        $request = fn (string $header) => Request::create('/v1/webhooks/video', 'POST', server: ['HTTP_WEBHOOK_SIGNATURE' => $header], content: $body);

        $this->assertTrue($this->provider()->verifyWebhook($request(StreamWebhook::sign($body, 'whsec'))));
        $this->assertFalse($this->provider()->verifyWebhook($request(StreamWebhook::sign($body, 'other'))));
        $this->assertFalse($this->provider()->verifyWebhook($request(StreamWebhook::sign($body, 'whsec', time() - 301))));
        $this->assertFalse($this->provider()->verifyWebhook($request(StreamWebhook::sign($body.' ', 'whsec').'x')));
        $this->assertFalse($this->provider()->verifyWebhook($request('')));
    }

    public function test_the_sniffer_knows_the_accepted_types_from_their_bytes(): void
    {
        $this->assertSame('image/jpeg', MimeSniffer::detect("\xFF\xD8\xFF\xE0rest"));
        $this->assertSame('image/png', MimeSniffer::detect("\x89PNG\r\n\x1A\nrest"));
        $this->assertSame('image/webp', MimeSniffer::detect('RIFF'."\0\0\0\0".'WEBPVP8 '));
        $this->assertSame('image/heic', MimeSniffer::detect("\0\0\0\x18ftypheic"));
        $this->assertSame('video/quicktime', MimeSniffer::detect("\0\0\0\x14ftypqt  "));
        $this->assertSame('video/mp4', MimeSniffer::detect("\0\0\0\x20ftypisom"));
        $this->assertNull(MimeSniffer::detect('%PDF-1.7'));
    }
}

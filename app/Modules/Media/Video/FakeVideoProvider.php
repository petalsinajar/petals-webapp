<?php

namespace App\Modules\Media\Video;

use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Data\UploadInstruction;
use App\Modules\Media\Data\VideoStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * In-memory video provider for tests. Webhooks use Stream's format and signature, signed
 * with eventapp.media.cloudflare_stream.webhook_secret, so the real webhook path is exercised.
 */
class FakeVideoProvider implements VideoProvider
{
    /** @var array<string, array<string, mixed>> Stream-shaped video objects by uid */
    public array $videos = [];

    /** @var list<string> */
    public array $deleted = [];

    public function createUpload(string $mediaId, int $maxDurationSeconds, CarbonImmutable $expiresAt): array
    {
        $uid = Str::lower(Str::random(32));
        $this->videos[$uid] = ['uid' => $uid, 'status' => ['state' => 'pendingupload'], 'meta' => ['media_id' => $mediaId], 'maxDurationSeconds' => $maxDurationSeconds];

        return [$uid, new UploadInstruction('POST', "https://upload.stream.test/{$uid}", $expiresAt, field: 'file')];
    }

    public function status(string $ref): VideoStatus
    {
        return StreamWebhook::status($this->videos[$ref] ?? ['uid' => $ref]);
    }

    public function verifyWebhook(Request $request): bool
    {
        return StreamWebhook::verify($request, config('eventapp.media.cloudflare_stream.webhook_secret'), config('eventapp.media.cloudflare_stream.webhook_tolerance_seconds'));
    }

    public function parseWebhook(array $payload): VideoStatus
    {
        return StreamWebhook::status($payload);
    }

    public function delete(string $ref): void
    {
        unset($this->videos[$ref]);
        $this->deleted[] = $ref;
    }

    public function playbackUrl(string $path): string
    {
        return rtrim((string) config('eventapp.media.cloudflare_stream.delivery_url'), '/').'/'.ltrim($path, '/');
    }

    // ---- Test helpers ---------------------------------------------------

    /** The app finished uploading; Stream is transcoding. */
    public function simulateUpload(string $ref, int $bytes): void
    {
        $this->videos[$ref] = [...$this->videos[$ref], 'size' => $bytes, 'status' => ['state' => 'inprogress']];
    }

    /**
     * Transcoding finished. Returns the webhook body Stream would send.
     *
     * @return array<string, mixed>
     */
    public function simulateReady(string $ref, float $seconds = 12.5, int $width = 1080, int $height = 1920): array
    {
        return $this->videos[$ref] = [...$this->videos[$ref], 'readyToStream' => true, 'duration' => $seconds,
            'input' => ['width' => $width, 'height' => $height], 'status' => ['state' => 'ready']];
    }

    /** @return array<string, mixed> */
    public function simulateError(string $ref, string $reason = 'ERR_NON_VIDEO'): array
    {
        return $this->videos[$ref] = [...$this->videos[$ref], 'status' => ['state' => 'error', 'errReasonText' => $reason]];
    }
}

<?php

namespace App\Modules\Media\Video;

use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Data\UploadInstruction;
use App\Modules\Media\Data\VideoStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Stream with direct creator uploads: we ask Stream for a one-time upload URL, the
 * app POSTs the file there, Stream transcodes to HLS and calls /v1/webhooks/video.
 */
class CloudflareStreamProvider implements VideoProvider
{
    /** @param  array{account_id: ?string, api_token: ?string, webhook_secret: ?string, delivery_url: ?string, webhook_tolerance_seconds: int}  $config */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('eventapp.media.cloudflare_stream'));
    }

    public function createUpload(string $mediaId, int $maxDurationSeconds, CarbonImmutable $expiresAt): array
    {
        $result = $this->api()->post('direct_upload', [
            'maxDurationSeconds' => $maxDurationSeconds,
            'expiry' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'requireSignedURLs' => false,
            'meta' => ['media_id' => $mediaId],
        ])->throw()->json('result');

        return [$result['uid'], new UploadInstruction('POST', $result['uploadURL'], $expiresAt, field: 'file')];
    }

    public function status(string $ref): VideoStatus
    {
        return StreamWebhook::status($this->api()->get($ref)->throw()->json('result'));
    }

    public function verifyWebhook(Request $request): bool
    {
        return StreamWebhook::verify($request, $this->config['webhook_secret'], $this->config['webhook_tolerance_seconds']);
    }

    public function parseWebhook(array $payload): VideoStatus
    {
        return StreamWebhook::status($payload);
    }

    public function delete(string $ref): void
    {
        $response = $this->api()->delete($ref);
        if ($response->failed() && $response->status() !== 404) {
            $response->throw();
        }
    }

    public function playbackUrl(string $path): string
    {
        return rtrim((string) $this->config['delivery_url'], '/').'/'.ltrim($path, '/');
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) $this->config['api_token'])
            ->baseUrl("https://api.cloudflare.com/client/v4/accounts/{$this->config['account_id']}/stream/")
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }
}

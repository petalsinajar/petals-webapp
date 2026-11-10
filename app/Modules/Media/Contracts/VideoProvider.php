<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\UploadInstruction;
use App\Modules\Media\Data\VideoStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Managed video: the app uploads straight to the provider, which transcodes to HLS and calls
 * our webhook when the video is ready (Cloudflare Stream in production, a fake in tests).
 */
interface VideoProvider
{
    /**
     * A one-off upload URL for one video.
     *
     * @return array{0: string, 1: UploadInstruction} provider ref and how to upload
     */
    public function createUpload(string $mediaId, int $maxDurationSeconds, CarbonImmutable $expiresAt): array;

    public function status(string $ref): VideoStatus;

    /** True when the webhook request really comes from the provider (signature and age). */
    public function verifyWebhook(Request $request): bool;

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): VideoStatus;

    public function delete(string $ref): void;

    /** Full URL for a stored playback path (HLS playlist or poster). */
    public function playbackUrl(string $path): string;
}

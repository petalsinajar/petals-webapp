<?php

namespace App\Modules\Media\Data;

/** The video provider's view of one video. Playback paths are relative to its delivery URL. */
final class VideoStatus
{
    public const PENDING_UPLOAD = 'pending_upload';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $ref,
        public readonly string $state,
        public readonly ?int $bytes = null,
        public readonly ?int $durationMs = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $hlsPath = null,
        public readonly ?string $posterPath = null,
        public readonly ?string $error = null,
    ) {}

    public function isReady(): bool
    {
        return $this->state === self::READY;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}

<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Data\UploadInstruction;
use Carbon\CarbonImmutable;

/**
 * Object storage for media (R2 in production, FakeMediaStorage in tests). The app uploads
 * straight to it with signed URLs; this server only signs, checks and processes.
 */
interface MediaStorage
{
    /** A single signed PUT, locked to this exact content type and size. */
    public function signedUpload(string $key, string $mime, int $bytes, CarbonImmutable $expiresAt): UploadInstruction;

    /** Start a multipart upload and sign a URL for every part. */
    public function startMultipart(string $key, string $mime, int $bytes, int $partBytes, CarbonImmutable $expiresAt): UploadInstruction;

    /** @param  list<array{part_number: int, etag: string}>  $parts */
    public function completeMultipart(string $key, string $uploadId, array $parts): void;

    public function abortMultipart(string $key, string $uploadId): void;

    /** Size and stored content type, or null when nothing is there. */
    public function head(string $key): ?StoredObject;

    public function exists(string $key): bool;

    /** The first bytes of an object, to check what it really is. */
    public function readStart(string $key, int $bytes = 4096): string;

    public function get(string $key): string;

    public function put(string $key, string $contents, string $mime): void;

    /** @param  list<string>  $keys */
    public function delete(array $keys): void;

    /** Public (CDN) URL for a key. */
    public function url(string $key): string;
}

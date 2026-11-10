<?php

namespace App\Modules\Media\Data;

use Carbon\CarbonImmutable;

/**
 * How the app sends the file: a single signed request, or a multipart plan.
 *
 *   single:    PUT `url` with exactly these `headers` (the type and size are signed)
 *   multipart: PUT each part's `url`, keep each response's ETag, then
 *              POST /v1/uploads/{media}/complete with { parts: [{ part_number, etag }] }
 *   form:      POST `url` as multipart/form-data with the file in `field` (video provider)
 */
final class UploadInstruction
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<array{part_number: int, url: string, bytes: int}>  $parts
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $url,
        public readonly CarbonImmutable $expiresAt,
        public readonly array $headers = [],
        public readonly array $parts = [],
        public readonly ?string $field = null,
        public readonly ?string $uploadId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'method' => $this->method,
            'url' => $this->url, // multipart: null, each part has its own
            'headers' => (object) $this->headers,
            'parts' => $this->parts ?: null,
            'field' => $this->field,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ], fn ($v) => $v !== null);
    }
}

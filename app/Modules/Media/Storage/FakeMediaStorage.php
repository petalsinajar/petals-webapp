<?php

namespace App\Modules\Media\Storage;

use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Data\UploadInstruction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * In-memory MediaStorage for tests. "Upload" with simulateUpload() / simulatePart(), the way
 * the app would PUT to a signed URL.
 */
class FakeMediaStorage implements MediaStorage
{
    /** @var array<string, array{contents: string, mime: ?string}> */
    public array $objects = [];

    /** @var array<string, array{key: string, mime: string, parts: array<int, string>}> */
    private array $multipart = [];

    public function __construct(private readonly string $cdnUrl = 'https://cdn.test') {}

    public function signedUpload(string $key, string $mime, int $bytes, CarbonImmutable $expiresAt): UploadInstruction
    {
        $url = 'https://uploads.test/'.$key.'?'.http_build_query(['X-Amz-Expires' => $expiresAt->getTimestamp(), 'X-Amz-Signature' => 'fake']);

        return new UploadInstruction('PUT', $url, $expiresAt, ['Content-Type' => $mime, 'Content-Length' => (string) $bytes]);
    }

    public function startMultipart(string $key, string $mime, int $bytes, int $partBytes, CarbonImmutable $expiresAt): UploadInstruction
    {
        $uploadId = 'mpu-'.Str::lower(Str::random(12));
        $this->multipart[$uploadId] = ['key' => $key, 'mime' => $mime, 'parts' => []];

        $count = (int) ceil($bytes / $partBytes);
        $parts = [];
        for ($n = 1; $n <= $count; $n++) {
            $parts[] = [
                'part_number' => $n,
                'url' => "https://uploads.test/{$key}?uploadId={$uploadId}&partNumber={$n}",
                'bytes' => $n < $count ? $partBytes : $bytes - $partBytes * ($count - 1),
            ];
        }

        return new UploadInstruction('multipart', null, $expiresAt, parts: $parts, uploadId: $uploadId);
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        $upload = $this->multipart[$uploadId] ?? throw new RuntimeException('NoSuchUpload');
        usort($parts, fn ($a, $b) => $a['part_number'] <=> $b['part_number']);

        $contents = '';
        foreach ($parts as $part) {
            $data = $upload['parts'][$part['part_number']] ?? throw new RuntimeException('InvalidPart');
            if (md5($data) !== trim($part['etag'], '"')) {
                throw new RuntimeException('InvalidPart');
            }
            $contents .= $data;
        }

        $this->objects[$key] = ['contents' => $contents, 'mime' => $upload['mime']];
        unset($this->multipart[$uploadId]);
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        unset($this->multipart[$uploadId]);
    }

    public function head(string $key): ?StoredObject
    {
        $object = $this->objects[$key] ?? null;

        return $object ? new StoredObject(strlen($object['contents']), $object['mime']) : null;
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function readStart(string $key, int $bytes = 4096): string
    {
        return substr($this->get($key), 0, $bytes);
    }

    public function get(string $key): string
    {
        return $this->objects[$key]['contents'] ?? throw new RuntimeException("No object at {$key}");
    }

    public function put(string $key, string $contents, string $mime): void
    {
        $this->objects[$key] = ['contents' => $contents, 'mime' => $mime];
    }

    public function delete(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->objects[$key]);
        }
    }

    public function url(string $key): string
    {
        return rtrim($this->cdnUrl, '/').'/'.ltrim($key, '/');
    }

    // ---- Test helpers ---------------------------------------------------

    /** What the app's PUT to a signed URL would leave behind. */
    public function simulateUpload(string $key, string $contents, ?string $mime): void
    {
        $this->objects[$key] = ['contents' => $contents, 'mime' => $mime];
    }

    /** Upload one part; returns its ETag. */
    public function simulatePart(string $uploadId, int $partNumber, string $contents): string
    {
        $this->multipart[$uploadId]['parts'][$partNumber] = $contents;

        return '"'.md5($contents).'"';
    }
}

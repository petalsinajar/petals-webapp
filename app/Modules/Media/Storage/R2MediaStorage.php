<?php

namespace App\Modules\Media\Storage;

use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Data\UploadInstruction;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/**
 * Cloudflare R2 through Laravel's s3 driver (the `r2` disk). The bucket is private; files are
 * read through the CDN domain. Signed URLs include the content type and length as signed
 * headers, so the upload must match what was declared.
 */
class R2MediaStorage implements MediaStorage
{
    public function __construct(
        private readonly string $disk,
        private readonly string $cdnUrl,
    ) {}

    public function signedUpload(string $key, string $mime, int $bytes, CarbonImmutable $expiresAt): UploadInstruction
    {
        ['url' => $url, 'headers' => $headers] = $this->disk()->temporaryUploadUrl($key, $expiresAt, [
            'ContentType' => $mime,
            'ContentLength' => $bytes,
        ]);

        // The client sets Host itself; everything else must be sent exactly as signed.
        $headers = collect($headers)->except(['Host', 'host'])->map(fn ($v) => is_array($v) ? implode(',', $v) : (string) $v)->all();

        return new UploadInstruction('PUT', $url, $expiresAt, $headers + ['Content-Type' => $mime, 'Content-Length' => (string) $bytes]);
    }

    public function startMultipart(string $key, string $mime, int $bytes, int $partBytes, CarbonImmutable $expiresAt): UploadInstruction
    {
        $uploadId = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => $mime,
        ])['UploadId'];

        $parts = [];
        $count = (int) ceil($bytes / $partBytes);
        for ($n = 1; $n <= $count; $n++) {
            $size = $n < $count ? $partBytes : $bytes - $partBytes * ($count - 1);
            $command = $this->client()->getCommand('UploadPart', [
                'Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId,
                'PartNumber' => $n, 'ContentLength' => $size,
            ]);
            $parts[] = [
                'part_number' => $n,
                'url' => (string) $this->client()->createPresignedRequest($command, $expiresAt)->getUri(),
                'bytes' => $size,
            ];
        }

        return new UploadInstruction('multipart', null, $expiresAt, parts: $parts, uploadId: $uploadId);
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn (array $p) => ['PartNumber' => $p['part_number'], 'ETag' => $p['etag']], $parts)],
        ]);
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        rescue(fn () => $this->client()->abortMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId]), report: false);
    }

    public function head(string $key): ?StoredObject
    {
        try {
            $result = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return new StoredObject((int) $result['ContentLength'], $result['ContentType'] ?? null);
    }

    public function exists(string $key): bool
    {
        return $this->head($key) !== null;
    }

    public function readStart(string $key, int $bytes = 4096): string
    {
        $result = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $key, 'Range' => 'bytes=0-'.($bytes - 1)]);

        return (string) $result['Body'];
    }

    public function get(string $key): string
    {
        return $this->disk()->get($key);
    }

    public function put(string $key, string $contents, string $mime): void
    {
        // Renditions never change (new upload, new key), so the CDN may cache them forever.
        $this->disk()->put($key, $contents, ['ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000, immutable']);
    }

    public function delete(array $keys): void
    {
        if ($keys !== []) {
            $this->disk()->delete($keys);
        }
    }

    public function url(string $key): string
    {
        return rtrim($this->cdnUrl, '/').'/'.ltrim($key, '/');
    }

    private function disk(): AwsS3V3Adapter
    {
        /** @var AwsS3V3Adapter */
        return Storage::disk($this->disk);
    }

    private function client(): S3Client
    {
        return $this->disk()->getClient();
    }

    private function bucket(): string
    {
        return (string) Arr::get($this->disk()->getConfig(), 'bucket');
    }
}

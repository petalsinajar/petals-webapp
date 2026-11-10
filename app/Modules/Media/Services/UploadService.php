<?php

namespace App\Modules\Media\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Data\UploadInstruction;
use App\Modules\Media\Data\VideoStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Jobs\ProcessMedia;
use App\Modules\Media\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Hands out direct upload URLs and checks what arrived. Files go from the app straight to R2
 * (images) or the video provider (video); this server never receives them.
 */
class UploadService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly VideoProvider $video,
    ) {}

    /**
     * @param  array{kind: string, mime: string, bytes: int, purpose: string}  $data  validated
     * @return array{0: Media, 1: UploadInstruction}
     */
    public function request(User $owner, ?Vendor $vendor, array $data): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config('eventapp.media.upload_ttl_minutes'));
        $kind = MediaKind::from($data['kind']);

        $media = new Media([
            'owner_id' => $owner->id,
            'vendor_id' => $vendor?->id,
            'kind' => $kind,
            'purpose' => $data['purpose'],
            'mime' => $data['mime'],
            'bytes' => $data['bytes'],
        ]);
        $media->id = strtolower((string) Str::ulid());

        if ($kind === MediaKind::Video) {
            [$ref, $upload] = $this->video->createUpload($media->id, config('eventapp.media.video.max_duration_seconds'), $expiresAt);
            $media->provider_ref = $ref;
        } else {
            $media->storage_key = 'uploads/'.now()->format('Y/m').'/'.$media->id;
            $upload = $data['bytes'] > config('eventapp.media.multipart_threshold_bytes')
                ? $this->storage->startMultipart($media->storage_key, $media->mime, $media->bytes, config('eventapp.media.multipart_part_bytes'), $expiresAt)
                : $this->storage->signedUpload($media->storage_key, $media->mime, $media->bytes, $expiresAt);
            $media->multipart_upload_id = $upload->uploadId;
        }

        $media->save();

        return [$media, $upload];
    }

    /**
     * The app says the upload finished. Checks the file is really there with the declared size
     * and type; a mismatch fails the media and deletes the file. Safe to call twice.
     *
     * @param  list<array{part_number: int, etag: string}>  $parts  multipart uploads only
     *
     * @throws ValidationException
     */
    public function complete(Media $media, array $parts = []): Media
    {
        $media = DB::transaction(fn () => Media::whereKey($media->id)->lockForUpdate()->firstOrFail());
        if ($media->status !== MediaStatus::Pending) {
            return $media;
        }

        $media->isImage() ? $this->checkImage($media, $parts) : $this->checkVideo($media);

        $media->update(['status' => MediaStatus::Uploaded, 'multipart_upload_id' => null]);
        ProcessMedia::dispatch($media->id);

        return $media->refresh();
    }

    private function checkImage(Media $media, array $parts): void
    {
        if ($media->multipart_upload_id) {
            if ($parts === []) {
                throw ValidationException::withMessages(['parts' => 'Send the ETag of every part.']);
            }
            try {
                $this->storage->completeMultipart($media->storage_key, $media->multipart_upload_id, $parts);
            } catch (Throwable) {
                throw ValidationException::withMessages(['parts' => 'Those parts don\'t match the upload. Upload any missing parts and try again.']);
            }
        }

        $object = $this->storage->head($media->storage_key);
        if (! $object) {
            throw ValidationException::withMessages(['upload' => 'We haven\'t received the file yet.']);
        }

        if ($object->bytes !== $media->bytes) {
            $this->reject($media, 'size_mismatch', "The file is {$object->bytes} bytes, not the {$media->bytes} declared.");
        }

        $actual = MimeSniffer::detect($this->storage->readStart($media->storage_key));
        if ($actual !== $media->mime) {
            $this->reject($media, 'type_mismatch', 'The file isn\'t the type declared ('.$media->mime.').');
        }
    }

    private function checkVideo(Media $media): void
    {
        $status = $this->video->status($media->provider_ref);

        if ($status->state === VideoStatus::PENDING_UPLOAD) {
            throw ValidationException::withMessages(['upload' => 'We haven\'t received the video yet.']);
        }
        if ($status->bytes !== null && $status->bytes !== $media->bytes) {
            $this->reject($media, 'size_mismatch', "The video is {$status->bytes} bytes, not the {$media->bytes} declared.");
        }
    }

    /** @throws ValidationException always */
    private function reject(Media $media, string $reason, string $message): never
    {
        app(MediaLibrary::class)->purgeFiles($media);
        $media->update(['status' => MediaStatus::Failed, 'failure_reason' => $reason, 'storage_key' => null]);

        throw ValidationException::withMessages(['upload' => $message]);
    }
}

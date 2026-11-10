<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\ContentModeration;
use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Data\VideoStatus;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Events\MediaFailed;
use App\Modules\Media\Events\MediaReady;
use App\Modules\Media\Models\Media;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Exceptions\DecoderException;

/**
 * uploaded → processing → ready | failed | rejected. Every step re-checks the status under a
 * row lock, so a retried job or a repeated webhook never processes a media twice.
 */
class MediaProcessor
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly VideoProvider $video,
        private readonly ContentModeration $moderation,
        private readonly ImageProcessor $images,
        private readonly MediaLibrary $library,
    ) {}

    public function process(string $mediaId): void
    {
        $media = $this->claim($mediaId, [MediaStatus::Uploaded, MediaStatus::Processing]);
        if (! $media) {
            return;
        }

        $media->isImage() ? $this->processImage($media) : $this->applyVideo($media, $this->video->status($media->provider_ref));
    }

    /** From the provider's webhook. Unknown videos and already-final media are ignored. */
    public function videoUpdated(VideoStatus $status): void
    {
        $id = Media::where('provider_ref', $status->ref)->value('id');
        $media = $id ? $this->claim($id, [MediaStatus::Pending, MediaStatus::Uploaded, MediaStatus::Processing]) : null;

        if ($media) {
            $this->applyVideo($media, $status);
        }
    }

    /** The job gave up after its retries. */
    public function giveUp(string $mediaId, string $reason): void
    {
        $media = $this->claim($mediaId, [MediaStatus::Uploaded, MediaStatus::Processing]);
        if ($media) {
            $this->fail($media, MediaStatus::Failed, $reason);
        }
    }

    private function processImage(Media $media): void
    {
        try {
            $result = $this->images->process($this->storage->get($media->storage_key));
        } catch (DecoderException) {
            $this->fail($media, MediaStatus::Failed, 'unreadable_image');

            return;
        }

        $variants = [];
        foreach ($result['renditions'] as $name => $bytes) {
            $variants[$name] = "media/{$media->id}/{$name}.webp";
            $this->storage->put($variants[$name], $bytes, 'image/webp');
        }

        $media->update([
            'width' => $result['width'],
            'height' => $result['height'],
            'blurhash' => $result['blurhash'],
            'variants' => $variants,
        ]);

        $this->finish($media);
    }

    private function applyVideo(Media $media, VideoStatus $status): void
    {
        if ($status->isFailed()) {
            $this->fail($media, MediaStatus::Failed, $status->error ?? 'video_failed');

            return;
        }
        if (! $status->isReady()) {
            $media->update(['status' => MediaStatus::Processing]); // the webhook brings it home

            return;
        }

        $media->update([
            'duration_ms' => $status->durationMs,
            'width' => $status->width,
            'height' => $status->height,
            'variants' => ['hls' => $status->hlsPath, 'poster' => $status->posterPath],
        ]);

        $this->finish($media);
    }

    /** Moderation, then ready. The original image (with its metadata) is deleted either way. */
    private function finish(Media $media): void
    {
        $verdict = $this->moderation->review($media);
        if (! $verdict->approved) {
            $this->fail($media, MediaStatus::Rejected, $verdict->reason ?? 'rejected');

            return;
        }

        if ($media->storage_key) {
            $this->storage->delete([$media->storage_key]);
        }
        $media->update(['status' => MediaStatus::Ready, 'storage_key' => null]);

        MediaReady::dispatch($media->id);
    }

    private function fail(Media $media, MediaStatus $status, string $reason): void
    {
        $this->library->purgeFiles($media);
        $media->update(['status' => $status, 'failure_reason' => mb_substr($reason, 0, 100), 'storage_key' => null, 'variants' => null]);

        MediaFailed::dispatch($media->id);
    }

    /**
     * Lock the row and move it to processing if it's in one of the given states.
     *
     * @param  list<MediaStatus>  $from
     */
    private function claim(string $mediaId, array $from): ?Media
    {
        return DB::transaction(function () use ($mediaId, $from) {
            $media = Media::whereKey($mediaId)->lockForUpdate()->first();
            if (! $media || ! in_array($media->status, $from, true)) {
                return null;
            }
            $media->update(['status' => MediaStatus::Processing]);

            return $media;
        });
    }
}

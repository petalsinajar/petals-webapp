<?php

namespace App\Modules\Media\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Jobs\DiscardMedia;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * How other modules use uploads: check media ids from a request, attach them to the post /
 * item / profile that uses them, and let go of ones no longer used.
 *
 *   $media = $library->resolve($ids, $user, $vendor, ['image'], field: 'media', for: $item);
 *   ...save $item...
 *   $library->attach($media, $item);
 *   $library->releaseExcept($item, $media->modelKeys());
 */
class MediaLibrary
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly VideoProvider $video,
    ) {}

    /**
     * Load and check media ids, in the order given. A media may be used when it belongs to the
     * caller (or was uploaded for $vendor), is an allowed kind, is ready (or, with
     * $allowProcessing, still on its way), and isn't already used by something else.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $kinds
     * @return Collection<int, Media>
     *
     * @throws ValidationException
     */
    public function resolve(array $ids, User $user, ?Vendor $vendor, array $kinds, string $field, ?Model $for = null, bool $allowProcessing = false): Collection
    {
        $ids = array_map('strtolower', array_values($ids));
        $found = Media::whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_map(
            fn (string $id, int $i) => $this->check($found->get($id), "{$field}.{$i}", $user, $vendor, $kinds, $for, $allowProcessing),
            $ids, array_keys($ids),
        ));
    }

    /** One optional image for a single field (avatar, logo, cover). */
    public function resolveOne(?string $id, User $user, ?Vendor $vendor, string $field, ?Model $for = null): ?Media
    {
        return $id === null ? null : $this->check(Media::find(strtolower($id)), $field, $user, $vendor, ['image'], $for, false);
    }

    /** @param  list<string>  $kinds */
    private function check(?Media $media, string $key, User $user, ?Vendor $vendor, array $kinds, ?Model $for, bool $allowProcessing): Media
    {
        $mine = $media && ($media->owner_id === $user->id || ($vendor && $media->vendor_id === $vendor->id));
        if (! $mine) {
            throw ValidationException::withMessages([$key => 'Upload not found.']);
        }
        if (! in_array($media->kind->value, $kinds, true)) {
            throw ValidationException::withMessages([$key => 'A '.$media->kind->value.' can\'t be used here.']);
        }
        $usable = $media->status === MediaStatus::Ready || ($allowProcessing && $media->status->isOnItsWay());
        if (! $usable) {
            throw ValidationException::withMessages([$key => match ($media->status) {
                MediaStatus::Pending => 'This upload hasn\'t been completed.',
                MediaStatus::Uploaded, MediaStatus::Processing => 'This upload is still processing.',
                default => 'This upload failed.',
            }]);
        }
        $usedElsewhere = $media->attachable_type !== null
            && ! ($for && $media->attachable_type === $for->getMorphClass() && $media->attachable_id === $for->getKey());
        if ($usedElsewhere) {
            throw ValidationException::withMessages([$key => 'This upload is already used somewhere else.']);
        }

        return $media;
    }

    /** Attach in order (position = index). */
    public function attach(iterable $media, Model $to): void
    {
        foreach (array_values(collect($media)->filter()->all()) as $position => $item) {
            $item->forceFill([
                'attachable_type' => $to->getMorphClass(),
                'attachable_id' => $to->getKey(),
                'position' => $position,
                'attached_at' => $item->attached_at ?? now(),
            ])->save();
        }
    }

    /**
     * Discard media attached to $to that it no longer uses.
     *
     * @param  list<string|null>  $keepIds
     */
    public function releaseExcept(Model $to, array $keepIds): void
    {
        $ids = Media::where(['attachable_type' => $to->getMorphClass(), 'attachable_id' => $to->getKey()])
            ->whereNotIn('id', array_filter($keepIds))
            ->pluck('id')->all();

        $this->discard($ids);
    }

    /** $to was deleted: discard all its media. */
    public function discardFor(Model $to): void
    {
        $this->releaseExcept($to, []);
    }

    /**
     * Detach now; delete files and rows on the media queue.
     *
     * @param  list<string>  $ids
     */
    public function discard(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Media::whereIn('id', $ids)->update(['attachable_type' => null, 'attachable_id' => null]);
        DiscardMedia::dispatch($ids)->afterCommit();
    }

    /** Delete a media's files (storage keys, provider video, unfinished multipart upload). */
    public function purgeFiles(Media $media): void
    {
        if ($media->multipart_upload_id && $media->storage_key) {
            $this->storage->abortMultipart($media->storage_key, $media->multipart_upload_id);
        }
        $this->storage->delete($media->storageKeys());
        if ($media->provider_ref) {
            $this->video->delete($media->provider_ref);
        }
    }

    /**
     * Payloads for a list of media models, in order.
     *
     * @param  iterable<Media>  $media
     * @return list<array<string, mixed>>
     */
    public static function payloads(iterable $media): array
    {
        return collect($media)->map(fn (Media $m) => $m->toPayload())->values()->all();
    }
}

<?php

namespace App\Modules\Media\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Contracts\MediaStorage;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An uploaded image or video. Stores storage keys and provider paths only; URLs are built
 * when responding (url(), toPayload()), so the CDN or provider domain can change any time.
 *
 * @property string $id
 * @property MediaKind $kind
 * @property MediaStatus $status
 * @property array<string, string>|null $variants
 */
class Media extends Model
{
    use HasUlids;

    protected $table = 'media';

    protected $guarded = ['id'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'status' => 'pending',
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'status' => MediaStatus::class,
            'variants' => 'array',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'position' => 'integer',
            'attached_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The post, item, package, user, vendor or event using it. */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /** Public URL of one rendition: thumb | feed | full for images, hls | poster for video. */
    public function url(string $variant): ?string
    {
        if ($this->status !== MediaStatus::Ready || ! isset($this->variants[$variant])) {
            return null;
        }

        return $this->isImage()
            ? app(MediaStorage::class)->url($this->variants[$variant])
            : app(VideoProvider::class)->playbackUrl($this->variants[$variant]);
    }

    /**
     * The media object every response uses.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $names = $this->isImage() ? ['thumb', 'feed', 'full'] : ['hls', 'poster'];

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'width' => $this->width,
            'height' => $this->height,
            'blurhash' => $this->blurhash,
            'urls' => $this->status === MediaStatus::Ready
                ? array_combine($names, array_map(fn (string $n) => $this->url($n), $names))
                : null,
            'duration_ms' => $this->duration_ms,
        ];
    }

    /** Every storage key this media holds, for deleting it. */
    public function storageKeys(): array
    {
        if (! $this->isImage()) {
            return [];
        }

        return array_values(array_filter([$this->storage_key, ...array_values($this->variants ?? [])]));
    }
}

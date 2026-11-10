<?php

namespace App\Modules\Social\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = [
        'kind' => 'post',
        'visibility' => 'public',
        'status' => 'published',
        'likes_count' => 0,
        'comments_count' => 0,
        'saves_count' => 0,
        'shop_taps_count' => 0,
        'score' => 0,
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'score' => 'float',
        ];
    }

    /** The user or vendor who posted. */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<PostTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(PostTag::class);
    }

    /**
     * Photos and videos, in order.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'attachable')->orderBy('position');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @param Builder<Post> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published');
    }
}

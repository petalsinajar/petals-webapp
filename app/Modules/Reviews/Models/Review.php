<?php

namespace App\Modules\Reviews\Models;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Models\Media;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Social\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One side's review of a completed booking. Customer → vendor reviews are public once
 * published; vendor → customer reviews are only shown to vendor teams the customer asks to
 * book. Both stay hidden (pending) until both sides have reviewed or the blind window ends.
 */
class Review extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ReviewDirection::class,
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'aspects' => 'array',
            'item_ids' => 'array',
            'published_at' => 'immutable_datetime',
            'edited_until' => 'immutable_datetime',
            'vendor_replied_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'attachable')->orderBy('position');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'target');
    }

    /** @param  Builder<Review>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ReviewStatus::Published);
    }

    /** @param  Builder<Review>  $query */
    public function scopeOfVendor(Builder $query): void
    {
        $query->where('direction', ReviewDirection::CustomerToVendor);
    }

    /** @param  Builder<Review>  $query */
    public function scopeWithPhotos(Builder $query): void
    {
        $query->whereHas('media');
    }

    public function isEditable(): bool
    {
        return $this->status === ReviewStatus::Pending && now()->lessThan($this->edited_until);
    }

    public function replyEditable(): bool
    {
        return $this->vendor_replied_at === null
            || now()->lessThan($this->vendor_replied_at->addHours(config('eventapp.reviews.reply_edit_hours')));
    }
}

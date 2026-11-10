<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A vendor bundle booked in one tap, e.g. "Kids' party for 30". price_minor is the fixed
 * bundle price; lines say what it contains.
 *
 * @property string $id
 */
class Package extends Model
{
    use HasUlids;

    protected $fillable = [
        'vendor_id', 'title', 'description', 'event_types', 'guest_min', 'guest_max',
        'price_minor', 'currency', 'status',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'price_minor' => 'integer',
            'guest_min' => 'integer',
            'guest_max' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<PackageLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PackageLine::class);
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
}

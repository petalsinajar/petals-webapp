<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One featured week, granted for a paid month. The vendor picks the week it runs
 * (placement window); while it runs, the vendor can appear in Sponsored slots.
 */
class FeaturedCredit extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['weeks' => 1, 'sequence' => 1];

    protected function casts(): array
    {
        return [
            'weeks' => 'integer',
            'sequence' => 'integer',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'placement_starts_at' => 'immutable_datetime',
            'placement_ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @param  Builder<FeaturedCredit>  $query */
    public function scopeRunningAt(Builder $query, \DateTimeInterface $at): void
    {
        $query->whereNotNull('used_at')->where('placement_starts_at', '<=', $at)->where('placement_ends_at', '>', $at);
    }
}

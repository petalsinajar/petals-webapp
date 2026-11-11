<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Days (in the business timezone, both ends included) on which a vendor takes no new
 * bookings. Bookings already made are untouched.
 *
 * @property string $id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 */
class Blackout extends Model
{
    use HasUlids;

    protected $table = 'vendor_blackouts';

    protected $fillable = ['vendor_id', 'starts_on', 'ends_on', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Blackouts touching any day from $from to $to (Y-m-d, inclusive). */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        // whereDate: the stored value may carry a time part (SQLite), which breaks plain string compares.
        $query->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from);
    }

    /**
     * The blocked days among $days (Y-m-d) for the vendor.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    public static function blockedDays(string $vendorId, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $sorted = $days;
        sort($sorted);
        $ranges = self::where('vendor_id', $vendorId)->overlapping($sorted[0], end($sorted))->get();

        return array_values(array_filter($days, fn (string $day) => $ranges->contains(
            fn (self $b) => $b->starts_on->toDateString() <= $day && $b->ends_on->toDateString() >= $day,
        )));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'reason' => $this->reason,
        ];
    }
}

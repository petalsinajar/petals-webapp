<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Services\CapacityWarnings;
use Carbon\CarbonImmutable;
use Database\Factories\ItemVariantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A stockable version of an item ("Gold", "White"). Bookings, reservations, packages and
 * post tags point at it, so a variant that was ever used is deactivated instead of deleted.
 *
 * @property string $id
 * @property string $item_id
 * @property int $quantity
 */
class ItemVariant extends Model
{
    /** @use HasFactory<ItemVariantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['item_id', 'name', 'sku', 'quantity', 'price_delta_minor', 'active', 'sort'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'quantity' => 0,
        'price_delta_minor' => 0,
        'active' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_delta_minor' => 'integer',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function newFactory(): ItemVariantFactory
    {
        return ItemVariantFactory::new();
    }

    protected static function booted(): void
    {
        // Stock changes must reach the day counters, whether they come from the API or the
        // Filament dashboard. Days that are now overbooked are collected for the caller to show.
        static::updated(function (ItemVariant $variant) {
            if (! $variant->wasChanged('quantity')) {
                return;
            }

            $today = CarbonImmutable::now(config('eventapp.business_timezone'))->toDateString();
            $days = app(AvailabilityStore::class)->setCapacity($variant->id, $variant->quantity, $today);

            if ($days !== []) {
                app(CapacityWarnings::class)->add($variant, $days);
            }
        });

        // Used variants are switched off instead: their history must keep pointing at them.
        static::deleting(function (ItemVariant $variant) {
            if ($variant->isInUse()) {
                $variant->forceFill(['active' => false])->save();

                return false;
            }
        });
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isInUse(): bool
    {
        return DB::table('booking_lines')->where('variant_id', $this->id)->exists()
            || DB::table('reservations')->where('variant_id', $this->id)->exists()
            || DB::table('package_lines')->where('variant_id', $this->id)->exists()
            || DB::table('post_tags')->where('variant_id', $this->id)->exists();
    }
}

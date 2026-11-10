<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Models\Media;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A rentable product. Each variant is a stockable version with its own quantity, and
 * availability is always tracked per variant.
 *
 * price_tiers: [{ min_qty, price_minor }] — the unit price drops as the quantity grows.
 *
 * @property string $id
 * @property string $vendor_id
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'vendor_id', 'category_id', 'title', 'description', 'specs', 'event_types', 'tags',
        'pricing_unit', 'price_tiers', 'currency', 'deposit_policy', 'damage_deposit_minor',
        'min_qty', 'requires_setup', 'instant_book', 'status',
    ];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'pricing_unit' => 'per_event',
        'damage_deposit_minor' => 0,
        'min_qty' => 1,
        'requires_setup' => false,
        'instant_book' => false,
        'rating_avg' => 0,
        'rating_count' => 0,
        'bookings_count' => 0,
        'saves_count' => 0,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'event_types' => 'array',
            'tags' => 'array',
            'price_tiers' => 'array',
            'deposit_policy' => 'array',
            'damage_deposit_minor' => 'integer',
            'min_qty' => 'integer',
            'requires_setup' => 'boolean',
            'instant_book' => 'boolean',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'bookings_count' => 'integer',
            'saves_count' => 'integer',
        ];
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ItemVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class)->orderBy('sort')->orderBy('id');
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

    /** Unit price in minor units for a quantity, using the best matching tier. */
    public function unitPriceFor(int $qty, ItemVariant|string|null $variant = null): int
    {
        $tiers = collect($this->price_tiers ?? [])->sortByDesc('min_qty');
        $tier = $tiers->first(fn (array $t) => $qty >= (int) $t['min_qty']) ?? $tiers->last();

        if (is_string($variant)) {
            $variant = $this->variants->firstWhere('id', $variant);
        }

        return max(0, (int) ($tier['price_minor'] ?? 0) + (int) ($variant?->price_delta_minor ?? 0));
    }

    public function currency(): string
    {
        // Read the column directly: with no value, $this->currency would look for a relation.
        return $this->attributes['currency'] ?? config('eventapp.currency');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

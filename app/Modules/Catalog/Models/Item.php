<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Catalog\Availability\AvailabilityStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A rentable product. Each embedded variant is a stockable version with its own quantity,
 * and availability is always tracked per variant.
 *
 * variants: [{ variant_id, name, sku, quantity, price_delta_minor, active }]
 * pricing:  { unit: per_event|per_day|per_hour, currency, tiers: [{ min_qty, price_minor }] }
 *
 * @property string $id
 * @property string $vendor_id
 * @property array<int, array<string, mixed>> $variants
 */
class Item extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'items';

    protected $fillable = [
        'vendor_id', 'category_id', 'title', 'description', 'media', 'item_attributes',
        'event_types', 'tags', 'pricing', 'deposit_policy', 'damage_deposit_minor',
        'min_qty', 'requires_setup', 'instant_book', 'variants', 'geo', 'status',
    ];

    protected $attributes = [
        'status' => 'draft',
        'min_qty' => 1,
        'damage_deposit_minor' => 0,
        'requires_setup' => false,
        'instant_book' => false,
        'stats' => ['bookings' => 0, 'saves' => 0, 'tagged_in_posts' => 0],
        'rating' => ['avg' => 0, 'count' => 0],
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'item_attributes' => 'array',
            'event_types' => 'array',
            'tags' => 'array',
            'pricing' => 'array',
            'deposit_policy' => 'array',
            'variants' => 'array',
            'geo' => 'array',
            'stats' => 'array',
            'rating' => 'array',
            'damage_deposit_minor' => 'integer',
            'min_qty' => 'integer',
            'requires_setup' => 'boolean',
            'instant_book' => 'boolean',
        ];
    }

    /**
     * Days where existing reservations now exceed a lowered stock level, filled after save.
     *
     * @var list<array{variant_id: string, days: list<string>}>
     */
    public array $capacityWarnings = [];

    protected static function booted(): void
    {
        // Stock changes must reach the availability counters, whether they come from the API
        // or the Filament dashboard.
        static::updated(function (Item $item) {
            $before = collect($item->getOriginal('variants') ?? [])->keyBy('variant_id');
            $today = CarbonImmutable::now(config('eventapp.business_timezone'))->toDateString();
            $store = app(AvailabilityStore::class);

            foreach ($item->variants ?? [] as $variant) {
                $old = $before->get($variant['variant_id']);
                if ($old && (int) $old['quantity'] !== (int) $variant['quantity']) {
                    $days = $store->setCapacity($variant['variant_id'], (int) $variant['quantity'], $today);
                    if ($days) {
                        $item->capacityWarnings[] = ['variant_id' => $variant['variant_id'], 'days' => $days];
                    }
                }
            }
        });

        // Every variant gets a stable id the first time it's saved.
        static::saving(function (Item $item) {
            $item->variants = collect($item->variants ?? [])->map(function (array $variant) {
                $variant['variant_id'] ??= (string) new ObjectId;
                $variant['quantity'] = (int) ($variant['quantity'] ?? 0);
                $variant['price_delta_minor'] = (int) ($variant['price_delta_minor'] ?? 0);
                $variant['active'] = (bool) ($variant['active'] ?? true);

                return $variant;
            })->values()->all();
        });
    }

    /** Cross-database relation: items live in MongoDB, vendors in SQL. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return array<string, mixed>|null */
    public function variant(string $variantId): ?array
    {
        return collect($this->variants ?? [])->firstWhere('variant_id', $variantId);
    }

    /** Unit price in minor units for a quantity, using the best matching tier. */
    public function unitPriceFor(int $qty, ?string $variantId = null): int
    {
        $tiers = collect(Arr::get($this->pricing, 'tiers', []))->sortByDesc('min_qty');
        $tier = $tiers->first(fn (array $t) => $qty >= (int) $t['min_qty']) ?? $tiers->last();

        $delta = $variantId ? (int) ($this->variant($variantId)['price_delta_minor'] ?? 0) : 0;

        return max(0, (int) ($tier['price_minor'] ?? 0) + $delta);
    }

    public function currency(): string
    {
        return Arr::get($this->pricing, 'currency', config('eventapp.currency'));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

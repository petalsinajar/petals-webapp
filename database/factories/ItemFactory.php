<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An active item in "chairs" with no variants; add them with withVariant().
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'category_id' => 'chairs',
            'title' => 'Chiavari chair',
            'description' => fake()->sentence(),
            'media' => [],
            'event_types' => ['wedding', 'birthday', 'corporate'],
            'tags' => [],
            'pricing_unit' => 'per_event',
            'price_tiers' => [['min_qty' => 1, 'price_minor' => 1500]],
            'currency' => 'GHS',
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        // Categories are platform data; make sure the one used exists.
        return $this->afterMaking(function (Item $item) {
            Category::firstOrCreate(['id' => $item->category_id], ['name' => Str::title($item->category_id)]);
        });
    }

    public function inCategory(string $category): static
    {
        return $this->state(['category_id' => $category]);
    }

    public function withVariant(array $attributes = []): static
    {
        return $this->has(ItemVariant::factory()->state($attributes), 'variants');
    }
}

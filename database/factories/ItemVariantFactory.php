<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVariant>
 */
class ItemVariantFactory extends Factory
{
    protected $model = ItemVariant::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => 'Gold',
            'sku' => null,
            'quantity' => 100,
            'price_delta_minor' => 0,
            'active' => true,
        ];
    }
}

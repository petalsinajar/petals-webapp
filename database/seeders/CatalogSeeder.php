<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Categories and demo inventory for the seeded vendors. Runs as part of DatabaseSeeder;
 * on its own (categories only if the demo vendors don't exist):
 *   php artisan db:seed --class=CatalogSeeder
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['id' => 'seating', 'name' => 'Seating', 'parent_id' => null, 'icon' => 'chair', 'sort' => 10],
            ['id' => 'chairs', 'name' => 'Chairs', 'parent_id' => 'seating', 'icon' => 'chair', 'sort' => 11],
            ['id' => 'tables', 'name' => 'Tables', 'parent_id' => 'seating', 'icon' => 'table', 'sort' => 12],
            ['id' => 'shelter', 'name' => 'Tents & canopies', 'parent_id' => null, 'icon' => 'tent', 'sort' => 20],
            ['id' => 'tents', 'name' => 'Tents', 'parent_id' => 'shelter', 'icon' => 'tent', 'sort' => 21],
            ['id' => 'decor', 'name' => 'Decor', 'parent_id' => null, 'icon' => 'sparkles', 'sort' => 30],
            ['id' => 'lighting', 'name' => 'Lighting', 'parent_id' => 'decor', 'icon' => 'bulb', 'sort' => 31],
            ['id' => 'sound', 'name' => 'Sound', 'parent_id' => null, 'icon' => 'speaker', 'sort' => 40],
            ['id' => 'catering', 'name' => 'Catering equipment', 'parent_id' => null, 'icon' => 'utensils', 'sort' => 50],
            ['id' => 'kids', 'name' => 'Kids’ party', 'parent_id' => null, 'icon' => 'balloon', 'sort' => 60],
            ['id' => 'staging', 'name' => 'Staging', 'parent_id' => null, 'icon' => 'stage', 'sort' => 70],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['id' => $category['id']], $category);
        }

        $royal = Vendor::where('handle', 'royal.canopies')->first();
        $glow = Vendor::where('handle', 'glow.decor')->first();
        $bass = Vendor::where('handle', 'bass.sound')->first();

        $this->item($royal, 'chairs', 'Chiavari chair', 1500, [['Gold', 300], ['White', 200], ['Clear', 120]], 100, 1200, instant: true);
        $this->item($royal, 'tables', 'Round banquet table (10 seats)', 6000, [['Standard', 60]]);
        $this->item($royal, 'tents', 'Marquee tent 20×10 m', 250000, [['White', 4]], requiresSetup: true);
        $this->item($glow, 'decor', 'Floral centrepiece', 12000, [['Blush', 40], ['Ivory', 40]]);
        $this->item($glow, 'lighting', 'Fairy light canopy', 80000, [['Warm white', 6]], requiresSetup: true);
        $this->item($bass, 'sound', 'PA system (2 speakers + mixer)', 150000, [['Medium', 5], ['Large', 2]]);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $variants  [name, quantity]
     */
    private function item(?Vendor $vendor, string $category, string $title, int $price, array $variants, ?int $bulkQty = null, ?int $bulkPrice = null, bool $instant = false, bool $requiresSetup = false): void
    {
        if (! $vendor) {
            return;
        }

        $tiers = [['min_qty' => 1, 'price_minor' => $price]];
        if ($bulkQty) {
            $tiers[] = ['min_qty' => $bulkQty, 'price_minor' => $bulkPrice];
        }

        $item = Item::create([
            'vendor_id' => $vendor->id,
            'category_id' => $category,
            'title' => $title,
            'description' => "{$title} for weddings, birthdays and corporate events.",
            'media' => [],
            'event_types' => ['wedding', 'birthday', 'corporate'],
            'tags' => [],
            'pricing_unit' => 'per_event',
            'price_tiers' => $tiers,
            'currency' => 'GHS',
            'min_qty' => 1,
            'instant_book' => $instant,
            'requires_setup' => $requiresSetup,
            'status' => 'active',
        ]);

        foreach ($variants as $sort => [$name, $quantity]) {
            $item->variants()->create(['name' => $name, 'quantity' => $quantity, 'sort' => $sort]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * MongoDB categories and demo inventory for the seeded vendors.
 * Run after DatabaseSeeder:  php artisan db:seed --class=CatalogSeeder
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['_id' => 'seating', 'name' => 'Seating', 'parent_id' => null, 'icon' => 'chair', 'order' => 10],
            ['_id' => 'chairs', 'name' => 'Chairs', 'parent_id' => 'seating', 'icon' => 'chair', 'order' => 11],
            ['_id' => 'tables', 'name' => 'Tables', 'parent_id' => 'seating', 'icon' => 'table', 'order' => 12],
            ['_id' => 'shelter', 'name' => 'Tents & canopies', 'parent_id' => null, 'icon' => 'tent', 'order' => 20],
            ['_id' => 'tents', 'name' => 'Tents', 'parent_id' => 'shelter', 'icon' => 'tent', 'order' => 21],
            ['_id' => 'decor', 'name' => 'Decor', 'parent_id' => null, 'icon' => 'sparkles', 'order' => 30],
            ['_id' => 'lighting', 'name' => 'Lighting', 'parent_id' => 'decor', 'icon' => 'bulb', 'order' => 31],
            ['_id' => 'sound', 'name' => 'Sound', 'parent_id' => null, 'icon' => 'speaker', 'order' => 40],
            ['_id' => 'catering', 'name' => 'Catering equipment', 'parent_id' => null, 'icon' => 'utensils', 'order' => 50],
            ['_id' => 'kids', 'name' => 'Kids’ party', 'parent_id' => null, 'icon' => 'balloon', 'order' => 60],
            ['_id' => 'staging', 'name' => 'Staging', 'parent_id' => null, 'icon' => 'stage', 'order' => 70],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['_id' => $category['_id']], $category);
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

        Item::create([
            'vendor_id' => $vendor->id,
            'category_id' => $category,
            'title' => $title,
            'description' => "{$title} for weddings, birthdays and corporate events.",
            'media' => [],
            'event_types' => ['wedding', 'birthday', 'corporate'],
            'tags' => [],
            'pricing' => ['unit' => 'per_event', 'currency' => 'GHS', 'tiers' => $tiers],
            'min_qty' => 1,
            'instant_book' => $instant,
            'requires_setup' => $requiresSetup,
            'variants' => array_map(fn ($v) => ['name' => $v[0], 'quantity' => $v[1]], $variants),
            'geo' => ['type' => 'Point', 'coordinates' => [(float) $vendor->base_lng, (float) $vendor->base_lat]],
            'status' => 'active',
        ]);
    }
}

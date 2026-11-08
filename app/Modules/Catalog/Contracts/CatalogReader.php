<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\VariantSnapshot;

/**
 * What other modules (bookings, social) are allowed to read from the catalog.
 * Other modules never query the inventory tables themselves; they go through this.
 */
interface CatalogReader
{
    public function variant(string $itemId, string $variantId, int $qty = 1): ?VariantSnapshot;

    /**
     * Item cards for a list of ids, keyed by id (for feeds and post tags).
     *
     * @param  list<string>  $itemIds
     * @return array<string, array<string, mixed>>
     */
    public function cards(array $itemIds): array;

    /** Vendor id that owns an item, or null if it doesn't exist. */
    public function vendorOf(string $itemId): ?string;

    /**
     * Active variants of active items in one category (exact match), for the given vendors.
     * Prices are the single-unit price.
     *
     * @param  list<string>  $vendorIds
     * @return list<VariantSnapshot>
     */
    public function activeVariantsInCategory(string $categoryId, array $vendorIds): array;

    /**
     * Category of each item that exists.
     *
     * @param  list<string>  $itemIds
     * @return array<string, string> item id => category id
     */
    public function categoriesOf(array $itemIds): array;
}

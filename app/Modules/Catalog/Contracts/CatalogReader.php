<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\VariantSnapshot;

/**
 * What other modules (bookings, social) are allowed to read from the catalog.
 * Keeps MongoDB details inside the Catalog module.
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
}

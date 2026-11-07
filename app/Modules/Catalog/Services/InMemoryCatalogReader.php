<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;

/**
 * Test double for the catalog. Register variants with put(); prices are flat per unit.
 */
class InMemoryCatalogReader implements CatalogReader
{
    /** @var array<string, VariantSnapshot> keyed "item|variant" */
    private array $variants = [];

    public function put(VariantSnapshot $variant): void
    {
        $this->variants[$variant->itemId.'|'.$variant->variantId] = $variant;
    }

    public function variant(string $itemId, string $variantId, int $qty = 1): ?VariantSnapshot
    {
        return $this->variants[$itemId.'|'.$variantId] ?? null;
    }

    public function cards(array $itemIds): array
    {
        $cards = [];
        foreach ($this->variants as $variant) {
            if (in_array($variant->itemId, $itemIds, true)) {
                $cards[$variant->itemId] = [
                    'id' => $variant->itemId,
                    'vendor_id' => $variant->vendorId,
                    'title' => $variant->title,
                    'image' => null,
                    'from_price_minor' => $variant->unitPriceMinor,
                    'currency' => $variant->currency,
                    'unit' => 'per_event',
                    'status' => $variant->active ? 'active' : 'paused',
                ];
            }
        }

        return $cards;
    }

    public function vendorOf(string $itemId): ?string
    {
        foreach ($this->variants as $variant) {
            if ($variant->itemId === $itemId) {
                return $variant->vendorId;
            }
        }

        return null;
    }
}

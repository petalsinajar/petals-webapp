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

    /** @var array<string, string> item id => category id */
    private array $categories = [];

    public function put(VariantSnapshot $variant, string $categoryId = 'chairs'): void
    {
        $this->variants[$variant->itemId.'|'.$variant->variantId] = $variant;
        $this->categories[$variant->itemId] = $categoryId;
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

    public function activeVariantsInCategory(string $categoryId, array $vendorIds): array
    {
        return array_values(array_filter($this->variants, fn (VariantSnapshot $v) => $v->active
            && ($this->categories[$v->itemId] ?? null) === $categoryId
            && in_array($v->vendorId, $vendorIds, true)));
    }

    public function categoriesOf(array $itemIds): array
    {
        return array_intersect_key($this->categories, array_flip($itemIds));
    }
}

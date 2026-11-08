<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SqlCatalogReader implements CatalogReader
{
    public function variant(string $itemId, string $variantId, int $qty = 1): ?VariantSnapshot
    {
        if (! Str::isUlid($itemId) || ! Str::isUlid($variantId)) {
            return null;
        }

        $variant = ItemVariant::with('item')->where('item_id', strtolower($itemId))->find(strtolower($variantId));

        return $variant ? $this->snapshot($variant->item, $variant, $qty) : null;
    }

    public function cards(array $itemIds): array
    {
        $ids = $this->ulids($itemIds);
        if ($ids === []) {
            return [];
        }

        return Item::whereIn('id', $ids)->get()->mapWithKeys(fn (Item $item) => [
            $item->id => [
                'id' => $item->id,
                'vendor_id' => $item->vendor_id,
                'title' => $item->title,
                'image' => Arr::first($item->media ?? []),
                'from_price_minor' => $item->unitPriceFor(PHP_INT_MAX),
                'currency' => $item->currency(),
                'unit' => $item->pricing_unit,
                'status' => $item->status,
            ],
        ])->all();
    }

    public function vendorOf(string $itemId): ?string
    {
        return Str::isUlid($itemId) ? Item::whereKey(strtolower($itemId))->value('vendor_id') : null;
    }

    public function activeVariantsInCategory(string $categoryId, array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        return ItemVariant::query()
            ->with('item')
            ->where('active', true)
            ->whereHas('item', fn ($q) => $q->where('category_id', $categoryId)
                ->whereIn('vendor_id', array_values($vendorIds))
                ->where('status', 'active'))
            ->orderBy('id')
            ->get()
            ->map(fn (ItemVariant $variant) => $this->snapshot($variant->item, $variant, 1))
            ->all();
    }

    public function categoriesOf(array $itemIds): array
    {
        $ids = $this->ulids($itemIds);
        if ($ids === []) {
            return [];
        }

        return Item::whereIn('id', $ids)->pluck('category_id', 'id')->map(fn ($c) => (string) $c)->all();
    }

    private function snapshot(Item $item, ItemVariant $variant, int $qty): VariantSnapshot
    {
        return new VariantSnapshot(
            itemId: $item->id,
            variantId: $variant->id,
            vendorId: $item->vendor_id,
            title: $variant->name !== '' ? "{$item->title} ({$variant->name})" : $item->title,
            quantity: $variant->quantity,
            unitPriceMinor: $item->unitPriceFor($qty, $variant),
            currency: $item->currency(),
            minQty: (int) $item->min_qty,
            damageDepositMinor: (int) $item->damage_deposit_minor,
            instantBook: (bool) $item->instant_book,
            requiresSetup: (bool) $item->requires_setup,
            active: $item->isActive() && $variant->active,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function ulids(array $ids): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($ids, fn ($id) => is_string($id) && Str::isUlid($id)))));
    }
}

<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Catalog\Models\Item;
use Illuminate\Support\Arr;

class MongoCatalogReader implements CatalogReader
{
    public function variant(string $itemId, string $variantId, int $qty = 1): ?VariantSnapshot
    {
        $item = $this->findItem($itemId);
        $variant = $item?->variant($variantId);

        if (! $item || ! $variant) {
            return null;
        }

        return $this->snapshot($item, $variant, $qty);
    }

    public function activeVariantsInCategory(string $categoryId, array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        return Item::where('category_id', $categoryId)
            ->whereIn('vendor_id', array_values($vendorIds))
            ->where('status', 'active')
            ->get()
            ->flatMap(fn (Item $item) => collect($item->variants ?? [])
                ->filter(fn (array $variant) => (bool) ($variant['active'] ?? true))
                ->map(fn (array $variant) => $this->snapshot($item, $variant, 1)))
            ->values()
            ->all();
    }

    public function categoriesOf(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, fn ($id) => preg_match('/^[a-f0-9]{24}$/', $id))));
        if ($ids === []) {
            return [];
        }

        return Item::whereIn('_id', $ids)->get(['_id', 'category_id'])
            ->mapWithKeys(fn (Item $item) => [$item->id => (string) $item->category_id])
            ->all();
    }

    /** @param  array<string, mixed>  $variant */
    private function snapshot(Item $item, array $variant, int $qty): VariantSnapshot
    {
        return new VariantSnapshot(
            itemId: $item->id,
            variantId: $variant['variant_id'],
            vendorId: $item->vendor_id,
            title: trim($item->title.' ('.$variant['name'].')'),
            quantity: (int) $variant['quantity'],
            unitPriceMinor: $item->unitPriceFor($qty, $variant['variant_id']),
            currency: $item->currency(),
            minQty: (int) $item->min_qty,
            damageDepositMinor: (int) $item->damage_deposit_minor,
            instantBook: (bool) $item->instant_book,
            requiresSetup: (bool) $item->requires_setup,
            active: $item->isActive() && (bool) ($variant['active'] ?? true),
        );
    }

    public function cards(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, fn ($id) => preg_match('/^[a-f0-9]{24}$/', $id))));
        if ($ids === []) {
            return [];
        }

        return Item::whereIn('_id', $ids)->get()->mapWithKeys(fn (Item $item) => [
            $item->id => [
                'id' => $item->id,
                'vendor_id' => $item->vendor_id,
                'title' => $item->title,
                'image' => Arr::first($item->media ?? []),
                'from_price_minor' => $item->unitPriceFor(PHP_INT_MAX),
                'currency' => $item->currency(),
                'unit' => Arr::get($item->pricing, 'unit', 'per_event'),
                'status' => $item->status,
            ],
        ])->all();
    }

    public function vendorOf(string $itemId): ?string
    {
        return $this->findItem($itemId)?->vendor_id;
    }

    private function findItem(string $id): ?Item
    {
        return preg_match('/^[a-f0-9]{24}$/', $id) ? Item::find($id) : null;
    }
}

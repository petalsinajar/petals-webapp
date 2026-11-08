<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ItemVariant;

/**
 * Days where a lowered stock level is now below what's already reserved. Filled by
 * ItemVariant's updated hook and read by whoever saved the variant (the inventory API, or
 * the Filament edit action). Bound per request/job, so warnings never leak between them.
 */
class CapacityWarnings
{
    /** @var list<array{item_id: string, variant_id: string, days: list<string>}> */
    private array $warnings = [];

    /** @param  list<string>  $days */
    public function add(ItemVariant $variant, array $days): void
    {
        $this->warnings[] = ['item_id' => $variant->item_id, 'variant_id' => $variant->id, 'days' => $days];
    }

    /**
     * Warnings for one item (or all), removing them from the list.
     *
     * @return list<array{item_id: string, variant_id: string, days: list<string>}>
     */
    public function pull(?string $itemId = null): array
    {
        $pulled = array_values(array_filter($this->warnings, fn (array $w) => ! $itemId || $w['item_id'] === $itemId));
        $this->warnings = array_values(array_filter($this->warnings, fn (array $w) => $itemId && $w['item_id'] !== $itemId));

        return $pulled;
    }
}

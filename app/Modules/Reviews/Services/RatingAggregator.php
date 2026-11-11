<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Reviews\Models\Review;

/**
 * vendors.rating_* and items.rating_* are recomputed from the published customer → vendor
 * reviews whenever one is published, removed or restored, never incremented, so they can't
 * drift.
 */
class RatingAggregator
{
    public function forReview(Review $review): void
    {
        $this->vendor($review->vendor_id);
        $this->items($review->item_ids ?? []);
    }

    public function vendor(string $vendorId): void
    {
        $this->apply(Vendor::whereKey($vendorId), Review::published()->ofVendor()->where('vendor_id', $vendorId));
    }

    /** @param  list<string>  $itemIds */
    public function items(array $itemIds): void
    {
        foreach (array_unique($itemIds) as $itemId) {
            $this->apply(Item::whereKey($itemId), Review::published()->ofVendor()->whereJsonContains('item_ids', $itemId));
        }
    }

    private function apply($target, $reviews): void
    {
        $row = $reviews->toBase()->selectRaw('COUNT(*) AS n, AVG(rating) AS avg')->first();

        $target->update([
            'rating_count' => (int) $row->n,
            'rating_avg' => round((float) ($row->avg ?? 0), 2),
        ]);
    }
}

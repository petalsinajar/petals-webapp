<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'media' => $this->media ?? [],
            'specs' => $this->specs ?: (object) [],
            'event_types' => $this->event_types ?? [],
            'tags' => $this->tags ?? [],
            'pricing' => [
                'unit' => $this->pricing_unit,
                'currency' => $this->currency(),
                'tiers' => array_values($this->price_tiers ?? []),
            ],
            'deposit_policy' => $this->deposit_policy,
            'damage_deposit_minor' => (int) $this->damage_deposit_minor,
            'min_qty' => (int) $this->min_qty,
            'requires_setup' => (bool) $this->requires_setup,
            'instant_book' => (bool) $this->instant_book,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ItemVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'quantity' => $v->quantity,
                'price_delta_minor' => $v->price_delta_minor,
                'active' => $v->active,
            ])->values()),
            'rating' => ['avg' => $this->rating_avg, 'count' => $this->rating_count],
            'stats' => ['bookings' => $this->bookings_count, 'saves' => $this->saves_count],
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
            'attributes' => $this->item_attributes ?? (object) [],
            'event_types' => $this->event_types ?? [],
            'tags' => $this->tags ?? [],
            'pricing' => [
                'unit' => Arr::get($this->pricing, 'unit', 'per_event'),
                'currency' => $this->currency(),
                'tiers' => array_values(Arr::get($this->pricing, 'tiers', [])),
            ],
            'deposit_policy' => $this->deposit_policy,
            'damage_deposit_minor' => (int) $this->damage_deposit_minor,
            'min_qty' => (int) $this->min_qty,
            'requires_setup' => (bool) $this->requires_setup,
            'instant_book' => (bool) $this->instant_book,
            'variants' => collect($this->variants ?? [])->map(fn (array $v) => [
                'id' => $v['variant_id'],
                'name' => $v['name'] ?? '',
                'sku' => $v['sku'] ?? null,
                'quantity' => (int) $v['quantity'],
                'price_delta_minor' => (int) ($v['price_delta_minor'] ?? 0),
                'active' => (bool) ($v['active'] ?? true),
            ])->values(),
            'rating' => $this->rating ?? ['avg' => 0, 'count' => 0],
            'stats' => $this->stats ?? (object) [],
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

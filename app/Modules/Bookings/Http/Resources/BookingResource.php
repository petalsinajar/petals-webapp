<?php

namespace App\Modules\Bookings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isVendorSide = $user && $user->id !== $this->customer_id;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'event_id' => $this->event_id,
            'checkout_id' => $this->checkout_id,
            'customer_id' => $this->customer_id,
            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id, 'handle' => $this->vendor->handle,
                'name' => $this->vendor->name, 'logo_url' => $this->vendor->logo_url,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'handle' => $this->customer->handle,
                'name' => $this->customer->display_name, 'avatar_url' => $this->customer->avatar_url,
            ]),
            'source' => $this->source,
            'status' => $this->status->value,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'variant_id' => $l->variant_id,
                'title' => $l->title_snapshot,
                'qty' => $l->qty,
                'unit_price_minor' => $l->unit_price_minor,
                'line_total_minor' => $l->line_total_minor,
            ])),
            'window' => [
                'start' => $this->window_start->toIso8601String(),
                'end' => $this->window_end->toIso8601String(),
            ],
            'fulfilment' => $this->fulfilment,
            'totals' => [
                'subtotal_minor' => $this->subtotal_minor,
                'delivery_minor' => $this->delivery_minor,
                'setup_minor' => $this->setup_minor,
                'discount_minor' => $this->discount_minor,
                'service_fee_minor' => $this->service_fee_minor,
                'total_minor' => $this->total_minor,
                'deposit_due_minor' => $this->deposit_due_minor,
                'damage_deposit_minor' => $this->damage_deposit_minor,
                'paid_minor' => $this->paid_minor,
                'refunded_minor' => $this->refunded_minor,
                'balance_due_minor' => $this->resource->balanceDueMinor(),
                'currency' => $this->currency,
            ],
            'vendor_earning_minor' => $this->when($isVendorSide, fn () => $this->resource->vendorEarningMinor()),
            'policy' => $this->policy_snapshot,
            'vendor_respond_by' => $this->vendor_respond_by?->toIso8601String(),
            'pay_by' => $this->pay_by?->toIso8601String(),
            'notes' => [
                'customer' => $this->notes['customer'] ?? null,
                'vendor_internal' => $this->when($isVendorSide, $this->notes['vendor_internal'] ?? null),
            ],
            'condition' => $this->condition ?? (object) [],
            'timeline' => $this->whenLoaded('statusEvents', fn () => $this->statusEvents->map(fn ($e) => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'note' => $e->note,
                'at' => $e->created_at->toIso8601String(),
            ])),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

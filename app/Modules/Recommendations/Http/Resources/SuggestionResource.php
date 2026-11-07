<?php

namespace App\Modules\Recommendations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A suggested vendor for an event, as the host sees it. */
class SuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'category_id' => $this->category_id,
            'reason' => $this->reason->value,
            'status' => $this->status->value,
            'vendor' => [
                'type' => 'vendor',
                'id' => $this->vendor->id,
                'handle' => $this->vendor->handle,
                'name' => $this->vendor->name,
                'logo_url' => $this->vendor->logo_url,
                'city' => $this->vendor->city,
                'is_verified' => $this->vendor->isVerified(),
                'rating' => ['avg' => $this->vendor->rating_avg, 'count' => $this->vendor->rating_count],
                'completed_bookings' => $this->vendor->completed_bookings_count,
            ],
            'from_price_minor' => $this->details['from_price_minor'] ?? null,
            'currency' => $this->details['currency'] ?? null,
            'distance_km' => $this->details['distance_km'] ?? null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
        ];
    }
}

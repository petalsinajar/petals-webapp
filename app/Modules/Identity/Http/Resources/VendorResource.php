<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public vendor profile (the business's social account). */
class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => 'vendor',
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'bio' => $this->bio,
            'logo_url' => $this->logo_url,   // the logo's thumb
            'cover_url' => $this->cover_url, // the cover's feed size
            'logo' => $this->logo?->toPayload(),
            'cover' => $this->cover?->toPayload(),
            'event_types' => $this->event_types ?? [],
            'categories' => $this->categories ?? [],
            'city' => $this->city,
            'service_area' => [
                'lat' => $this->base_lat,
                'lng' => $this->base_lng,
                'radius_km' => $this->service_radius_km,
            ],
            'contact' => $this->contact ?? (object) [],
            'is_verified' => $this->isVerified(),
            'policies' => [
                'deposit_percent' => $this->policy('deposit_percent'),
                'cancellation' => $this->policy('cancellation'),
                'lead_time_hours' => $this->policy('lead_time_hours'),
                'buffer_hours' => $this->policy('buffer_hours'),
            ],
            'delivery' => $this->delivery ?? (object) [],
            'rating' => ['avg' => $this->rating_avg, 'count' => $this->rating_count],
            // Computed nightly (vendors:trust-signals); null until there are enough requests.
            'trust' => [
                'response_time_minutes' => $this->response_time_minutes,
                'acceptance_rate' => $this->acceptance_rate,
                // Only shown when it's worth knowing.
                'cancellation_rate' => $this->when(
                    $this->cancellation_rate !== null && $this->cancellation_rate > config('eventapp.trust.show_cancellation_rate_above'),
                    fn () => $this->cancellation_rate,
                ),
            ],
            'counts' => [
                'followers' => $this->followers_count,
                'posts' => $this->posts_count,
                'completed_bookings' => $this->completed_bookings_count,
            ],
            'is_following' => $this->whenHas('is_following', fn () => (bool) $this->is_following),
            'my_role' => $this->whenHas('my_role'),
        ];
    }
}

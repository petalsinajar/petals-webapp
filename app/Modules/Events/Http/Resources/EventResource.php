<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Identity\Http\Resources\ActorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isHost = $request->user() && $this->resource->isHostedBy($request->user());

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_url' => $this->cover_url,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'timezone' => $this->timezone,
            'venue' => [
                'name' => $this->venue_name,
                'address' => $this->venue_address,
                'city' => $this->venue_city,
                'lat' => $this->venue_lat,
                'lng' => $this->venue_lng,
            ],
            'guest_count' => $this->guest_count,
            'budget_minor' => $this->when($isHost, $this->budget_minor),
            'currency' => $this->currency,
            'visibility' => $this->visibility,
            'theme' => $this->theme ?? (object) [],
            'company' => $this->when($isHost, $this->company),
            'status' => $this->status,
            'host' => new ActorResource($this->whenLoaded('host')),
            'counts' => [
                'going' => $this->going_count,
                // Unset on a just-created model until it's re-read; the column defaults to 0.
                'maybe' => $this->maybe_count ?? 0,
                'declined' => $this->declined_count ?? 0,
                'posts' => $this->posts_count,
                'bookings' => $this->bookings_count,
            ],
            'my_rsvp' => $this->whenHas('my_rsvp'),
            'is_host' => $isHost,
        ];
    }
}

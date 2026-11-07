<?php

namespace App\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'title' => $this->title,
            'description' => $this->description,
            'event_types' => $this->event_types ?? [],
            'guest_range' => ['min' => $this->guest_min, 'max' => $this->guest_max],
            'lines' => $this->lines ?? [],
            'price_minor' => (int) $this->price_minor,
            'currency' => $this->currency ?? config('eventapp.currency'),
            'media' => $this->media ?? [],
            'status' => $this->status,
        ];
    }
}

<?php

namespace App\Modules\Reviews\Http\Resources;

use App\Modules\Identity\Http\Resources\ActorResource;
use App\Modules\Media\Services\MediaLibrary;
use App\Modules\Reviews\Enums\ReviewDirection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review. Every review is of a completed booking, so each carries verified_booking and
 * what was booked for (event type and date). Load booking.event, media, customer and vendor.
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $byCustomer = $this->direction === ReviewDirection::CustomerToVendor;
        $mine = $request->user()?->id === $this->author_id;
        $event = $this->booking?->event;

        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'status' => $this->status->value,
            'rating' => $this->rating,
            'aspects' => $this->aspects ?? (object) [],
            'body' => $this->body,
            'media' => MediaLibrary::payloads($this->media),
            'item_ids' => $this->item_ids ?? [],
            // Customers by their profile; vendor reviews by the business, not the staff member.
            'author' => new ActorResource($byCustomer ? $this->customer : $this->vendor),
            'vendor_id' => $this->vendor_id,
            'customer_id' => $this->customer_id,
            'verified_booking' => true,
            'booking' => [
                'event_type' => $event?->type,
                'event_date' => ($event?->starts_at ?? $this->booking?->window_start)?->toDateString(),
            ],
            'vendor_reply' => $this->vendor_reply === null ? null : [
                'body' => $this->vendor_reply,
                'replied_at' => $this->vendor_replied_at?->toIso8601String(),
            ],
            'is_mine' => $mine,
            'editable_until' => $this->when($mine, fn () => $this->isEditable() ? $this->edited_until->toIso8601String() : null),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

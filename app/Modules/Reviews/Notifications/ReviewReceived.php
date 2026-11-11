<?php

namespace App\Modules\Reviews\Notifications;

use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Models\Review;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A review about you was published: to the vendor's owners and managers, or to the customer.
 * A customer is only told a review exists; vendor → customer reviews stay private.
 */
class ReviewReceived extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Review $review)
    {
        $this->onQueue(config('eventapp.reviews.queue'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    private function aboutVendor(): bool
    {
        return $this->review->direction === ReviewDirection::CustomerToVendor;
    }

    public function toPush(object $notifiable): PushMessage
    {
        $review = $this->review;

        return $this->aboutVendor()
            ? new PushMessage(
                'New review',
                ($review->customer?->display_name ?? 'A customer')." rated {$review->vendor->name} {$review->rating}★.",
                route: "/vendors/{$review->vendor_id}/reviews",
                data: ['review_id' => $review->id],
                forBusiness: true,
            )
            : new PushMessage(
                'You were reviewed',
                "{$review->vendor->name} reviewed your booking.",
                route: "/bookings/{$review->booking_id}",
                data: ['booking_id' => $review->booking_id],
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_received',
            'target' => $this->aboutVendor()
                ? ['type' => 'review', 'id' => $this->review->id]
                : ['type' => 'booking', 'id' => $this->review->booking_id],
            'direction' => $this->review->direction->value,
            // The customer is never shown the vendor's rating of them.
            'rating' => $this->aboutVendor() ? $this->review->rating : null,
            'vendor' => ['id' => $this->review->vendor_id, 'name' => $this->review->vendor->name],
            'booking_id' => $this->review->booking_id,
        ];
    }
}

<?php

namespace App\Modules\Reviews\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** "How did it go?" to whoever hasn't reviewed a completed booking yet. */
class ReviewReminder extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ReviewDirection $direction,
        public readonly int $stageDays,
    ) {
        $this->onQueue(config('eventapp.reviews.queue'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    private function byVendor(): bool
    {
        return $this->direction === ReviewDirection::VendorToCustomer;
    }

    public function toPush(object $notifiable): PushMessage
    {
        $body = $this->byVendor()
            ? 'How was '.($this->booking->customerName() ?? 'your customer')." on booking #{$this->booking->number}? Your review helps other vendors."
            : 'How was '.($this->booking->vendor?->name ?? 'your rental').'? Leave a review to help other hosts.';

        return new PushMessage(
            'Leave a review',
            $body,
            route: "/bookings/{$this->booking->id}/review",
            data: ['booking_id' => $this->booking->id],
            forBusiness: $this->byVendor(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_reminder',
            'target' => ['type' => 'booking', 'id' => $this->booking->id],
            'direction' => $this->direction->value,
            'booking_number' => $this->booking->number,
            'vendor' => ['id' => $this->booking->vendor_id, 'name' => $this->booking->vendor?->name],
            'group_key' => 'review:'.$this->booking->id,
        ];
    }
}

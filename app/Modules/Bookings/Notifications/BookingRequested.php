<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Sent to a vendor's owners and managers. Add SMS here: a missed request is a lost sale. */
class BookingRequested extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $customer = $this->booking->customer?->display_name ?: 'A customer';

        return new PushMessage(
            'New booking request',
            "{$customer} requested booking #{$this->booking->number}. Respond soon to keep it.",
            // The app switches to this business and opens its Requests tab.
            route: "/vendor/requests?vendor={$this->booking->vendor_id}",
            data: ['booking_id' => $this->booking->id],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_requested',
            'target' => ['type' => 'booking', 'id' => $this->booking->id],
            'vendor_id' => $this->booking->vendor_id,
            'booking_number' => $this->booking->number,
            'total_minor' => $this->booking->total_minor,
            'currency' => $this->booking->currency,
            'starts_at' => $this->booking->window_start->toIso8601String(),
            'respond_by' => $this->booking->vendor_respond_by?->toIso8601String(),
            'customer' => ['id' => $this->booking->customer_id, 'name' => $this->booking->customer?->display_name],
            'group_key' => 'booking:'.$this->booking->id,
        ];
    }
}

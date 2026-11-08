<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Sent to the customer and to the vendor's managers. */
class BookingStatusChanged extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // Add an SMS channel here for booking-critical alerts.
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $status = str_replace('_', ' ', $this->booking->status->value);
        $isCustomer = $notifiable->getKey() === $this->booking->customer_id;
        $body = $isCustomer
            ? 'Your booking with '.($this->booking->vendor?->name ?? 'the vendor')." is now {$status}."
            : "Booking #{$this->booking->number} is now {$status}.";

        return new PushMessage(
            "Booking #{$this->booking->number}",
            $body,
            // Customers land on the event it's for; the vendor's team on the
            // business's Requests tab.
            route: match (true) {
                $isCustomer && $this->booking->event_id !== null => "/events/{$this->booking->event_id}",
                $isCustomer => null,
                default => "/vendor/requests?vendor={$this->booking->vendor_id}",
            },
            data: ['booking_id' => $this->booking->id],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_'.$this->booking->status->value,
            'target' => ['type' => 'booking', 'id' => $this->booking->id],
            'booking_number' => $this->booking->number,
            'status' => $this->booking->status->value,
            'vendor' => ['id' => $this->booking->vendor_id, 'name' => $this->booking->vendor?->name],
            'group_key' => 'booking:'.$this->booking->id,
        ];
    }
}

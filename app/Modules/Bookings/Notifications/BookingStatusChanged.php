<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class BookingStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // Add 'fcm' (push) and an SMS channel here for booking-critical alerts.
        return ['database'];
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

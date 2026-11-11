<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Enums\BookingStatus;
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
        $isCustomer = $notifiable->getKey() === $this->booking->customer_id;
        $body = $isCustomer ? $this->customerBody() : $this->vendorBody();

        return new PushMessage(
            "Booking #{$this->booking->number}",
            $body,
            // Customers land on the booking; the vendor's team on the business's Requests tab.
            route: $isCustomer
                ? "/bookings/{$this->booking->id}"
                : "/vendor/requests?vendor={$this->booking->vendor_id}",
            data: ['booking_id' => $this->booking->id],
            forBusiness: ! $isCustomer,
        );
    }

    private function customerBody(): string
    {
        $vendor = $this->booking->vendor?->name ?? 'The vendor';
        $number = $this->booking->number;

        return match ($this->booking->status) {
            BookingStatus::Confirmed => $this->booking->source === 'vendor_manual'
                ? "{$vendor} recorded your booking #{$number}. It's confirmed."
                : "{$vendor} accepted your booking. It's confirmed.",
            BookingStatus::Accepted => "{$vendor} accepted your booking.",
            BookingStatus::Declined => "{$vendor} can't take booking #{$number}.",
            BookingStatus::Expired => "Booking #{$number} expired: {$vendor} didn't respond in time.",
            BookingStatus::Cancelled => "Booking #{$number} was cancelled.",
            BookingStatus::OutForDelivery => "{$vendor} is on the way with your rentals.",
            BookingStatus::Delivered => "Your rentals from {$vendor} have arrived.",
            default => "Your booking with {$vendor} is now ".str_replace('_', ' ', $this->booking->status->value).'.',
        };
    }

    private function vendorBody(): string
    {
        $number = $this->booking->number;

        return match ($this->booking->status) {
            BookingStatus::Cancelled => "Booking #{$number} was cancelled.",
            BookingStatus::Expired => "Booking #{$number} expired before anyone answered.",
            default => "Booking #{$number} is now ".str_replace('_', ' ', $this->booking->status->value).'.',
        };
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

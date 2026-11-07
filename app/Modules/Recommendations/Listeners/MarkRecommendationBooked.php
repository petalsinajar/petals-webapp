<?php

namespace App\Modules\Recommendations\Listeners;

use App\Modules\Bookings\Events\BookingCreated;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use App\Modules\Recommendations\Models\Recommendation;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Credits a suggestion when the host books that vendor for that event. */
class MarkRecommendationBooked implements ShouldQueue
{
    public function viaQueue(): string
    {
        return config('eventapp.recommendations.queue');
    }

    public function handle(BookingCreated $booking): void
    {
        Recommendation::where('event_id', $booking->eventId)
            ->where('vendor_id', $booking->vendorId)
            ->where('status', '!=', RecommendationStatus::Booked->value)
            ->update([
                'status' => RecommendationStatus::Booked->value,
                'booked_booking_id' => $booking->bookingId,
                'updated_at' => now(),
            ]);
    }
}

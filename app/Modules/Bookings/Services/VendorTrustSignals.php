<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Identity\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How reliably a vendor answers and keeps bookings, from the last N months of app
 * bookings (bookings a vendor recorded themselves don't count):
 *  - response_time_minutes: median time from request to accept/decline
 *  - acceptance_rate: accepted / (accepted + declined + expired)
 *  - cancellation_rate: cancelled by the vendor / bookings the vendor accepted
 * Below eventapp.trust.min_requests answered requests, all three stay null.
 */
class VendorTrustSignals
{
    /** @return array{response_time_minutes: ?int, acceptance_rate: ?float, cancellation_rate: ?float} */
    public function compute(Vendor $vendor): array
    {
        $since = now()->subMonths(config('eventapp.trust.window_months'));

        $events = DB::table('booking_status_events as e')
            ->join('bookings as b', 'b.id', '=', 'e.booking_id')
            ->where('b.vendor_id', $vendor->id)
            ->where('b.source', 'app')
            ->where('b.created_at', '>=', $since)
            ->get(['b.id', 'b.created_at as requested_at', 'b.customer_id', 'e.from_status', 'e.to_status', 'e.actor_id', 'e.created_at']);

        $answers = $events->where('from_status', 'pending_vendor')->whereIn('to_status', ['accepted', 'confirmed', 'declined', 'expired']);
        $accepted = $answers->whereIn('to_status', ['accepted', 'confirmed']);
        $byVendor = $answers->where('to_status', '!=', 'expired')->whereNotNull('actor_id');

        if ($answers->count() < config('eventapp.trust.min_requests')) {
            return ['response_time_minutes' => null, 'acceptance_rate' => null, 'cancellation_rate' => null];
        }

        $minutes = $byVendor
            ->map(fn ($e) => max(0, (int) CarbonImmutable::parse($e->requested_at)->diffInMinutes(CarbonImmutable::parse($e->created_at))))
            ->sort()->values();

        $acceptedIds = $accepted->pluck('id')->unique();
        $vendorCancelled = $events
            ->where('to_status', 'cancelled')
            ->filter(fn ($e) => $e->actor_id !== null && $e->actor_id !== $e->customer_id && $acceptedIds->contains($e->id))
            ->pluck('id')->unique();

        return [
            'response_time_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->median()),
            'acceptance_rate' => round($accepted->count() / $answers->count(), 4),
            'cancellation_rate' => $acceptedIds->isEmpty() ? null : round($vendorCancelled->count() / $acceptedIds->count(), 4),
        ];
    }

    public function refresh(Vendor $vendor): void
    {
        $vendor->forceFill([...$this->compute($vendor), 'trust_computed_at' => now()])->saveQuietly();
    }
}

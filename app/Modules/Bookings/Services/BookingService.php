<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Jobs\ReleaseBookingStock;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusChanged;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Services\RefundService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The booking lifecycle. Every status change goes through transition(), which locks the
 * row, checks the move is allowed, and records it in booking_status_events.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityStore $store,
        private readonly RefundCalculator $refunds,
        private readonly RefundService $refundService,
    ) {}

    // ---- Vendor actions --------------------------------------------------

    public function accept(Booking $booking, User $actor): Booking
    {
        $this->authorizeVendor($actor, $booking, manage: true);

        $payBy = now()->addHours(config('eventapp.bookings.deposit_window_hours'))->toImmutable();

        $booking = $this->transition($booking, BookingStatus::Accepted, $actor, null, function (Booking $b) use ($payBy) {
            $b->pay_by = $payBy;
        });

        // The hold now waits for the deposit instead of the vendor.
        foreach ($booking->lines as $line) {
            if ($line->reservation_id) {
                $this->store->extendHold($line->reservation_id, $payBy);
            }
        }

        return $booking;
    }

    public function decline(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        $this->authorizeVendor($actor, $booking, manage: true);

        return $this->transition($booking, BookingStatus::Declined, $actor, $reason);
    }

    public function dispatch(Booking $booking, User $actor): Booking
    {
        $this->authorizeVendor($actor, $booking);

        return $this->transition($booking, BookingStatus::OutForDelivery, $actor);
    }

    /** Delivered (or picked up by the customer). Either side can confirm it. */
    public function deliver(Booking $booking, User $actor, array $photos = []): Booking
    {
        $isCustomer = $booking->customer_id === $actor->id;
        if (! $isCustomer) {
            $this->authorizeVendor($actor, $booking);
        }

        return $this->transition($booking, BookingStatus::Delivered, $actor, null, function (Booking $b) use ($photos) {
            $b->condition = [...($b->condition ?? []), 'delivered_photos' => $photos];
        });
    }

    /** Items checked back in. A damage claim sends the booking to review instead. */
    public function markReturned(Booking $booking, User $actor, int $damageClaimMinor = 0, array $photos = [], ?string $note = null): Booking
    {
        $this->authorizeVendor($actor, $booking);

        if ($damageClaimMinor > $booking->damage_deposit_minor && $booking->damage_deposit_minor > 0) {
            throw ValidationException::withMessages(['damage_claim_minor' => 'A claim cannot exceed the damage deposit.']);
        }

        $to = $damageClaimMinor > 0 ? BookingStatus::Disputed : BookingStatus::Returned;

        return $this->transition($booking, $to, $actor, $note, function (Booking $b) use ($damageClaimMinor, $photos) {
            $b->returned_at = now()->toImmutable();
            $b->condition = [...($b->condition ?? []), 'returned_photos' => $photos, 'damage_claim_minor' => $damageClaimMinor];
        });
    }

    // ---- Customer actions -----------------------------------------------

    /** Cancel by the customer (refund per policy) or the vendor (full refund). */
    public function cancel(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        $byCustomer = $booking->customer_id === $actor->id;
        if (! $byCustomer) {
            $this->authorizeVendor($actor, $booking, manage: true);
        }

        $refund = 0;
        $booking = $this->transition($booking, BookingStatus::Cancelled, $actor, $reason, function (Booking $b) use ($byCustomer, &$refund) {
            $refund = $byCustomer ? $this->refunds->customerRefund($b, now()) : $b->paid_minor;
        });

        if ($refund > 0) {
            $this->refundService->refundBooking($booking, $refund, $byCustomer ? 'Cancelled by customer' : 'Cancelled by vendor');
        }

        return $booking->refresh();
    }

    // ---- System actions -------------------------------------------------

    /** Called when a deposit (or more) has been paid. */
    public function confirmAfterPayment(Booking $booking): Booking
    {
        if ($booking->status !== BookingStatus::Accepted || $booking->depositOutstandingMinor() > 0) {
            return $booking;
        }

        return $this->transition($booking, BookingStatus::Confirmed, null, 'Deposit received');
    }

    public function expire(Booking $booking): Booking
    {
        return $this->transition($booking, BookingStatus::Expired, null, 'Not completed in time');
    }

    /** Returned (or resolved dispute) past the dispute window: book the vendor's earnings. */
    public function complete(Booking $booking, ?User $actor = null, ?string $note = null): Booking
    {
        return $this->transition($booking, BookingStatus::Completed, $actor, $note, function (Booking $b) {
            $claim = (int) ($b->condition['damage_claim_minor'] ?? 0);
            $key = "complete:{$b->id}";

            LedgerEntry::record('commission', $b->commission_minor, $b->currency, $b->id, $b->vendor_id, memo: 'Platform commission', idempotencyKey: "$key:commission");
            LedgerEntry::record('vendor_earning', $b->vendorEarningMinor() + $claim, $b->currency, $b->id, $b->vendor_id, memo: 'Rental earnings', idempotencyKey: "$key:earning");

            if ($b->damage_deposit_minor > 0) {
                LedgerEntry::record('damage_release', $b->damage_deposit_minor - $claim, $b->currency, $b->id, memo: 'Damage deposit returned to customer', idempotencyKey: "$key:damage");
            }

            $b->vendor()->increment('completed_bookings_count');
        });
    }

    // ---------------------------------------------------------------------

    /**
     * @param  (Closure(Booking): void)|null  $mutate  extra changes saved in the same transaction
     */
    public function transition(Booking $booking, BookingStatus $to, ?User $actor, ?string $note = null, ?Closure $mutate = null): Booking
    {
        $updated = DB::transaction(function () use ($booking, $to, $actor, $note, $mutate) {
            /** @var Booking $locked */
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "A booking that is {$from->value} can't become {$to->value}.",
                ]);
            }

            $locked->status = $to;
            if ($mutate) {
                $mutate($locked);
            }
            $locked->save();

            $locked->statusEvents()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            return $locked;
        });

        // Side effects run after commit. Stock goes back once the items are back (returned or
        // disputed) or the booking ends early; completed bookings were already released.
        if (! $to->holdsStock() && $to !== BookingStatus::Completed) {
            ReleaseBookingStock::dispatch($updated->id);
        }

        // Tell the other side.
        if ($actor?->id === $updated->customer_id) {
            Notification::send($updated->vendor->managers()->get(), new BookingStatusChanged($updated));
        } else {
            $updated->customer?->notify(new BookingStatusChanged($updated));
        }

        return $updated->load('lines');
    }

    private function authorizeVendor(User $actor, Booking $booking, bool $manage = false): void
    {
        $ability = $manage ? 'manage' : 'operate';

        if (! Gate::forUser($actor)->allows($ability, $booking->vendor)) {
            throw new AuthorizationException('Only the vendor team can do this.');
        }
    }
}

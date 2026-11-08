<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private User $owner;

    private Vendor $vendor;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        $this->host = User::factory()->create();
        $this->event = Event::factory()->create(['host_id' => $this->host->id, 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(6)]);
    }

    private function checkout(array $lines, array $extra = []): TestResponse
    {
        $this->signIn($this->host);

        return $this->postJson('/v1/checkouts', ['event_id' => $this->event->id, 'lines' => $lines, ...$extra]);
    }

    public function test_full_booking_lifecycle_from_request_to_payout(): void
    {
        $chairs = $this->variant($this->vendor, ['quantity' => 200, 'unitPriceMinor' => 1200]);

        // 1. Host checks out 150 chairs: a booking request is created and stock is held.
        $checkout = $this->checkout([['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 150]])
            ->assertCreated()
            ->assertJsonPath('bookings.0.status', 'pending_vendor')
            ->assertJsonPath('bookings.0.totals.subtotal_minor', 180000)
            ->json();

        $bookingId = $checkout['bookings'][0]['id'];
        $day = $this->event->starts_at->setTimezone('Africa/Accra')->toDateString();
        $this->assertSame(50, $this->store()->remaining($chairs->variantId, 200, [$day])[$day]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);

        // 2. Nothing to pay until the vendor accepts.
        $this->postJson("/v1/checkouts/{$checkout['id']}/pay", ['purpose' => 'deposit'])->assertStatus(422);

        // 3. Vendor accepts.
        $this->signIn($this->owner);
        $this->postJson("/v1/bookings/{$bookingId}/accept")->assertOk()->assertJsonPath('status', 'accepted');

        // 4. Host pays the deposit; the provider webhook confirms the booking.
        $this->signIn($this->host);
        $pay = $this->postJson("/v1/checkouts/{$checkout['id']}/pay", ['purpose' => 'deposit'], ['Idempotency-Key' => 'k1'])
            ->assertCreated()->json();
        $booking = Booking::find($bookingId);
        $this->assertSame($booking->deposit_due_minor, $pay['amount_minor']);

        $this->postJson("/v1/dev/payments/{$pay['payment_id']}/succeed")->assertOk();
        $this->postJson("/v1/dev/payments/{$pay['payment_id']}/succeed")->assertOk(); // duplicate webhook

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame($booking->deposit_due_minor, $booking->paid_minor);
        $this->assertSame(1, LedgerEntry::where(['booking_id' => $bookingId, 'type' => 'customer_payment'])->count());

        // 5. Delivery and return free the stock.
        $this->signIn($this->owner);
        $this->postJson("/v1/bookings/{$bookingId}/dispatch")->assertOk()->assertJsonPath('status', 'out_for_delivery');
        $this->postJson("/v1/bookings/{$bookingId}/deliver")->assertOk()->assertJsonPath('status', 'delivered');
        $this->postJson("/v1/bookings/{$bookingId}/return")->assertOk()->assertJsonPath('status', 'returned');
        $this->assertSame(200, $this->store()->remaining($chairs->variantId, 200, [$day])[$day]);

        // 6. After the dispute window, the vendor's earnings are booked.
        $this->travel(49)->hours();
        Artisan::call('bookings:complete-returned');

        $booking->refresh();
        $this->assertSame('completed', $booking->status->value);
        $this->assertSame($booking->vendorEarningMinor(), (int) LedgerEntry::where(['booking_id' => $bookingId, 'type' => 'vendor_earning'])->sum('amount_minor'));
        $this->assertSame(1, $booking->vendor->refresh()->completed_bookings_count);
    }

    public function test_checkout_is_all_or_nothing_across_vendors(): void
    {
        $otherVendor = Vendor::factory()->ownedBy()->create();
        $chairs = $this->variant($this->vendor, ['quantity' => 200]);
        $speakers = $this->variant($otherVendor, ['quantity' => 2]);

        $this->checkout([
            ['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 100],
            ['item_id' => $speakers->itemId, 'variant_id' => $speakers->variantId, 'qty' => 5],
        ])->assertStatus(409)->assertJsonPath('stock.available', 2);

        $day = $this->event->starts_at->setTimezone('Africa/Accra')->toDateString();
        $this->assertSame(200, $this->store()->remaining($chairs->variantId, 200, [$day])[$day]);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_failure_while_writing_bookings_rolls_back_the_stock_too(): void
    {
        $chairs = $this->variant($this->vendor, ['quantity' => 200]);
        $speakers = $this->variant(Vendor::factory()->ownedBy()->create(), ['quantity' => 10]);

        // Stock is reserved first; then writing the second booking blows up.
        $written = 0;
        Booking::creating(function () use (&$written) {
            if (++$written === 2) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->checkout([
                ['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 100],
                ['item_id' => $speakers->itemId, 'variant_id' => $speakers->variantId, 'qty' => 5],
            ]);
            $this->fail('Expected the checkout to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $day = $this->event->starts_at->setTimezone('Africa/Accra')->toDateString();
        $this->assertSame(200, $this->store()->remaining($chairs->variantId, 200, [$day])[$day]);
        $this->assertSame(10, $this->store()->remaining($speakers->variantId, 10, [$day])[$day]);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('checkouts', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_two_hosts_cannot_book_the_last_chairs(): void
    {
        $chairs = $this->variant($this->vendor, ['quantity' => 100]);
        $line = [['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 60]];

        $this->checkout($line)->assertCreated();

        $otherHost = User::factory()->create();
        $otherEvent = Event::factory()->create(['host_id' => $otherHost->id, 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at]);
        $this->signIn($otherHost);
        $this->postJson('/v1/checkouts', ['event_id' => $otherEvent->id, 'lines' => $line])->assertStatus(409);
    }

    public function test_instant_book_items_skip_vendor_approval(): void
    {
        $chairs = $this->variant($this->vendor, ['instantBook' => true]);

        $this->checkout([['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 10]])
            ->assertCreated()->assertJsonPath('bookings.0.status', 'accepted');
    }

    public function test_unpaid_holds_expire_and_release_stock(): void
    {
        $chairs = $this->variant($this->vendor, ['quantity' => 100]);
        $bookingId = $this->checkout([['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 100]])->json('bookings.0.id');

        $this->travel(25)->hours();
        Artisan::call('inventory:release-expired-holds');

        $this->assertSame('expired', Booking::find($bookingId)->status->value);
        $day = $this->event->starts_at->setTimezone('Africa/Accra')->toDateString();
        $this->assertSame(100, $this->store()->remaining($chairs->variantId, 100, [$day])[$day]);
    }

    public function test_customer_cancellation_refunds_per_policy(): void
    {
        $chairs = $this->variant($this->vendor, ['instantBook' => true, 'unitPriceMinor' => 10000]);
        $checkout = $this->checkout([['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 10]])->json();
        $bookingId = $checkout['bookings'][0]['id'];

        $pay = $this->postJson("/v1/checkouts/{$checkout['id']}/pay", ['purpose' => 'full'])->json();
        $this->postJson("/v1/dev/payments/{$pay['payment_id']}/succeed");

        $booking = Booking::find($bookingId);
        $this->assertSame('confirmed', $booking->status->value);

        // 30 days out: 100% refund of the rental, minus the non-refundable service fee.
        $this->postJson("/v1/bookings/{$bookingId}/cancel", ['reason' => 'Changed plans'])->assertOk()->assertJsonPath('status', 'cancelled');

        $booking->refresh();
        $this->assertSame($booking->paid_minor - $booking->service_fee_minor, $booking->refunded_minor);
        $this->assertSame('partially_refunded', Payment::find($pay['payment_id'])->status);
    }

    public function test_vendors_cannot_act_on_other_vendors_bookings(): void
    {
        $chairs = $this->variant($this->vendor);
        $bookingId = $this->checkout([['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 10]])->json('bookings.0.id');

        $stranger = User::factory()->create();
        Vendor::factory()->ownedBy($stranger)->create();
        $this->signIn($stranger);

        $this->postJson("/v1/bookings/{$bookingId}/accept")->assertForbidden();
    }

    public function test_only_hosts_can_rent_for_an_event(): void
    {
        $chairs = $this->variant($this->vendor);
        $this->signIn(User::factory()->create());

        $this->postJson('/v1/checkouts', [
            'event_id' => $this->event->id,
            'lines' => [['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 10]],
        ])->assertForbidden();
    }
}

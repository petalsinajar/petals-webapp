<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingRequested;
use App\Modules\Bookings\Notifications\BookingStatusChanged;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Launch mode: no payment in the app. The customer requests, the vendor accepts (→ confirmed)
 * or declines, and both hear about each step.
 */
class BookingWithoutPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private User $owner;

    private Vendor $vendor;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['eventapp.payments.in_app' => false]);
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        $this->host = User::factory()->create();
        $this->event = Event::factory()->create([
            'host_id' => $this->host->id, 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(6),
        ]);
    }

    /** @return array<string, mixed> the created booking */
    private function request(array $variant = [], int $qty = 150): array
    {
        $chairs = $this->variant($this->vendor, ['quantity' => 200, 'unitPriceMinor' => 1200, ...$variant]);
        $this->signIn($this->host);

        return $this->postJson('/v1/checkouts', [
            'event_id' => $this->event->id,
            'lines' => [['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => $qty]],
        ])->assertCreated()->json('bookings.0');
    }

    private function remainingOnTheDay(string $variantId): int
    {
        $day = $this->event->starts_at->setTimezone('Africa/Accra')->toDateString();

        return $this->store()->remaining($variantId, 200, [$day])[$day];
    }

    public function test_the_vendor_is_asked_and_accepting_confirms_with_the_stock_kept(): void
    {
        $booking = $this->request();
        $this->assertSame('pending_vendor', $booking['status']);
        Notification::assertSentTo($this->owner, BookingRequested::class);

        $this->signIn($this->owner);
        $this->postJson("/v1/bookings/{$booking['id']}/accept")->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('pay_by', null);
        Notification::assertSentTo($this->host, BookingStatusChanged::class);

        // Long after any hold would have run out, the booking and its stock stand.
        $this->travel(5)->days();
        Artisan::call('inventory:release-expired-holds');
        $this->assertSame('confirmed', Booking::find($booking['id'])->status->value);
        $variantId = $booking['lines'][0]['variant_id'];
        $this->assertSame(50, $this->remainingOnTheDay($variantId));

        // From here the vendor runs the job as usual.
        $this->postJson("/v1/bookings/{$booking['id']}/dispatch")->assertOk()->assertJsonPath('status', 'out_for_delivery');
    }

    public function test_declining_frees_the_stock_and_tells_the_customer(): void
    {
        $booking = $this->request();

        $this->signIn($this->owner);
        $this->postJson("/v1/bookings/{$booking['id']}/decline", ['reason' => 'Fully booked that weekend'])
            ->assertOk()->assertJsonPath('status', 'declined');

        Notification::assertSentTo($this->host, BookingStatusChanged::class);
        $this->assertSame(200, $this->remainingOnTheDay($booking['lines'][0]['variant_id']));
    }

    public function test_instant_book_items_still_wait_for_the_vendor(): void
    {
        $booking = $this->request(['instantBook' => true]);

        $this->assertSame('pending_vendor', $booking['status']);
        $this->assertNull($booking['pay_by']);
    }

    public function test_the_customer_can_cancel_a_request_and_sees_it_in_their_bookings(): void
    {
        $booking = $this->request();

        $this->getJson('/v1/me/bookings')->assertOk()->assertJsonPath('data.0.id', $booking['id']);
        $this->getJson("/v1/bookings/{$booking['id']}")->assertOk()->assertJsonPath('timeline.0.to', 'pending_vendor');
        $this->postJson("/v1/bookings/{$booking['id']}/cancel", ['reason' => 'Changed plans'])
            ->assertOk()->assertJsonPath('status', 'cancelled');

        Notification::assertSentTo($this->owner, BookingStatusChanged::class);
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusChanged;
use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Vendors record bookings agreed outside the app (phone, walk-in) so the stock comes off the
 * calendar. The customer may or may not have the app.
 */
class RecordedBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vendor $vendor;

    private VariantSnapshot $chairs;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        $this->chairs = $this->variant($this->vendor, ['quantity' => 100, 'unitPriceMinor' => 1200]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => ['name' => 'Akosua Mensah', 'phone' => '024 123 4567'],
            'deliver_at' => now()->addDays(10)->setTime(8, 0)->toIso8601String(),
            'collect_at' => now()->addDays(11)->setTime(18, 0)->toIso8601String(),
            'fulfilment' => ['type' => 'delivery', 'address' => 'East Legon, Accra'],
            'lines' => [['item_id' => $this->chairs->itemId, 'variant_id' => $this->chairs->variantId, 'qty' => 80]],
            'delivery_minor' => 5000,
            'paid_minor' => 20000,
            'note' => 'Called on Sunday',
        ], $overrides);
    }

    private function remaining(): int
    {
        $day = now()->addDays(10)->setTimezone('Africa/Accra')->toDateString();

        return $this->store()->remaining($this->chairs->variantId, 100, [$day])[$day];
    }

    public function test_a_walk_in_booking_is_confirmed_and_takes_the_stock(): void
    {
        $this->signIn($this->owner);

        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('source', 'vendor_manual')
            ->assertJsonPath('customer.id', null)
            ->assertJsonPath('customer.name', 'Akosua Mensah')
            ->assertJsonPath('customer.phone', '+233241234567')
            ->assertJsonPath('totals.subtotal_minor', 96000)
            ->assertJsonPath('totals.total_minor', 101000)
            ->assertJsonPath('totals.paid_minor', 20000)
            ->assertJsonPath('totals.service_fee_minor', 0)
            ->assertJsonPath('vendor_earning_minor', 101000)
            ->assertJsonPath('notes.vendor_internal', 'Called on Sunday')
            ->assertJsonPath('timeline.0.note', 'Recorded by the vendor');

        $this->assertSame(20, $this->remaining());

        // It shows with the agreed bookings and runs like any other from here.
        $id = Booking::sole()->id;
        $this->getJson("/v1/vendors/{$this->vendor->id}/bookings?status%5B%5D=confirmed")
            ->assertOk()->assertJsonPath('data.0.customer.name', 'Akosua Mensah');
        $this->postJson("/v1/bookings/{$id}/dispatch")->assertOk()->assertJsonPath('status', 'out_for_delivery');
        Notification::assertNothingSent();
    }

    public function test_a_customer_on_the_app_is_linked_and_told(): void
    {
        $customer = User::factory()->create(['phone' => '+233241234567']);
        $this->signIn($this->owner);

        $id = $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload())
            ->assertCreated()->assertJsonPath('customer.id', $customer->id)->json('id');

        Notification::assertSentTo($customer, BookingStatusChanged::class, function ($n) use ($customer) {
            return str_contains($n->toPush($customer)->body, 'recorded your booking');
        });

        $this->signIn($customer);
        $this->getJson('/v1/me/bookings')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.notes.vendor_internal');
    }

    public function test_dates_already_taken_are_refused_naming_the_item(): void
    {
        $this->signIn($this->owner);
        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload())->assertCreated();

        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.qty' => 'Only 20 Chiavari chair']);
        $this->assertSame(1, Booking::count());
        $this->assertSame(20, $this->remaining());
    }

    public function test_only_owners_and_managers_can_record_and_only_their_own_items(): void
    {
        $staff = User::factory()->create();
        $this->vendor->members()->create(['user_id' => $staff->id, 'role' => VendorRole::Staff, 'status' => 'active']);
        $this->signIn($staff);
        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload())->assertForbidden();

        $other = $this->variant(Vendor::factory()->ownedBy()->create());
        $this->signIn($this->owner);
        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload([
            'lines' => [['item_id' => $other->itemId, 'variant_id' => $other->variantId, 'qty' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0');
    }

    public function test_pickup_needs_no_address_and_charges_no_delivery(): void
    {
        $this->signIn($this->owner);

        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload([
            'fulfilment' => ['type' => 'pickup', 'address' => null],
        ]))->assertCreated()->assertJsonPath('totals.delivery_minor', 0)->assertJsonPath('fulfilment.type', 'pickup');

        $this->postJson("/v1/vendors/{$this->vendor->id}/bookings", $this->payload([
            'fulfilment' => ['type' => 'delivery', 'address' => null],
        ]))->assertUnprocessable()->assertJsonValidationErrors('fulfilment.address');
    }
}

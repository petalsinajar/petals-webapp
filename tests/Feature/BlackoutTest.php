<?php

namespace Tests\Feature;

use App\Modules\Catalog\Data\VariantSnapshot;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Vendors block out days; customers can't book them, and availability shows them closed. */
class BlackoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vendor $vendor;

    private VariantSnapshot $chairs;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(now()->setTimezone('Africa/Accra')->setDate(2026, 11, 11)->setTime(9, 0));

        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        $this->chairs = $this->variant($this->vendor, ['quantity' => 100]);
    }

    private function block(string $from, string $to, ?string $reason = null)
    {
        return $this->postJson("/v1/vendors/{$this->vendor->id}/blackouts", [
            'starts_on' => $from, 'ends_on' => $to, 'reason' => $reason,
        ]);
    }

    public function test_owners_block_list_and_reopen_days(): void
    {
        $this->signIn($this->owner);

        $id = $this->block('2026-12-24', '2026-12-26', 'Christmas')
            ->assertCreated()
            ->assertJsonPath('starts_on', '2026-12-24')
            ->assertJsonPath('ends_on', '2026-12-26')
            ->assertJsonPath('reason', 'Christmas')
            ->json('id');

        $this->getJson("/v1/vendors/{$this->vendor->id}/blackouts?from=2026-12-01&to=2026-12-31")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/v1/vendors/{$this->vendor->id}/blackouts?from=2027-01-01&to=2027-01-31")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/v1/vendors/{$this->vendor->id}/blackouts/{$id}")->assertOk();
        $this->getJson("/v1/vendors/{$this->vendor->id}/blackouts?from=2026-12-01&to=2026-12-31")
            ->assertJsonCount(0, 'data');
    }

    public function test_past_days_and_backwards_ranges_are_refused(): void
    {
        $this->signIn($this->owner);

        $this->block('2026-11-10', '2026-11-12')->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->block('2026-12-26', '2026-12-24')->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->block('2026-11-11', '2026-11-11')->assertCreated();
    }

    public function test_staff_can_see_but_not_block(): void
    {
        $staff = User::factory()->create();
        $this->vendor->members()->create(['user_id' => $staff->id, 'role' => VendorRole::Staff, 'status' => 'active']);
        $this->signIn($staff);

        $this->getJson("/v1/vendors/{$this->vendor->id}/blackouts?from=2026-12-01&to=2026-12-31")->assertOk();
        $this->block('2026-12-24', '2026-12-26')->assertForbidden();
        $this->signIn(User::factory()->create());
        $this->getJson("/v1/vendors/{$this->vendor->id}/blackouts?from=2026-12-01&to=2026-12-31")->assertForbidden();
    }

    public function test_customers_cannot_book_blocked_days_and_see_them_closed(): void
    {
        $this->signIn($this->owner);
        $this->block('2026-12-24', '2026-12-26', 'Christmas')->assertCreated();

        $host = $this->signIn();
        $this->getJson("/v1/items/{$this->chairs->itemId}/availability?variant_id={$this->chairs->variantId}&from=2026-12-23&to=2026-12-24")
            ->assertOk()
            ->assertJsonPath('days.0.available', 100)
            ->assertJsonPath('days.0.closed', false)
            ->assertJsonPath('days.1.available', 0)
            ->assertJsonPath('days.1.closed', true);

        $event = Event::factory()->create([
            'host_id' => $host->id,
            'starts_at' => '2026-12-25 14:00:00', 'ends_at' => '2026-12-25 20:00:00',
        ]);
        $this->postJson('/v1/checkouts', [
            'event_id' => $event->id,
            'lines' => [['item_id' => $this->chairs->itemId, 'variant_id' => $this->chairs->variantId, 'qty' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors(["fulfilment.{$this->vendor->id}.deliver_at" => "isn't taking bookings on 25 Dec"]);

        // Outside the block it goes through.
        $later = Event::factory()->create([
            'host_id' => $host->id,
            'starts_at' => '2026-12-28 14:00:00', 'ends_at' => '2026-12-28 20:00:00',
        ]);
        $this->postJson('/v1/checkouts', [
            'event_id' => $later->id,
            'lines' => [['item_id' => $this->chairs->itemId, 'variant_id' => $this->chairs->variantId, 'qty' => 10]],
        ])->assertCreated();
    }
}

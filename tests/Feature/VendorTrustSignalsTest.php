<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Reviews\Pages\ManageReviews;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Reviews\Models\Review;
use App\Modules\Social\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Nightly trust signals on the vendor profile, and the reviews admin page. */
class VendorTrustSignalsTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
    }

    /** A request answered after $minutes with $answer, optionally cancelled later by the vendor or the customer. */
    private function request(int $minutes, string $answer, ?string $cancelledBy = null, string $source = 'app', int $monthsAgo = 1): void
    {
        $customer = User::factory()->create();
        $requestedAt = now()->subMonths($monthsAgo);
        $booking = Booking::create([
            'number' => Booking::nextNumber(), 'customer_id' => $customer->id, 'vendor_id' => $this->vendor->id, 'source' => $source,
            'window_start' => $requestedAt->copy()->addDays(20), 'window_end' => $requestedAt->copy()->addDays(21),
            'fulfilment' => ['type' => 'pickup'], 'subtotal_minor' => 1000, 'total_minor' => 1000, 'deposit_due_minor' => 0,
            'currency' => 'GHS', 'commission_percent' => 10, 'commission_minor' => 100, 'policy_snapshot' => [],
            'status' => $cancelledBy ? 'cancelled' : $answer,
        ]);
        $booking->forceFill(['created_at' => $requestedAt])->save();

        $booking->statusEvents()->create([
            'from_status' => 'pending_vendor', 'to_status' => $answer,
            'actor_id' => $answer === 'expired' ? null : $this->owner->id, 'created_at' => $requestedAt->copy()->addMinutes($minutes),
        ]);
        if ($cancelledBy) {
            $booking->statusEvents()->create([
                'from_status' => $answer, 'to_status' => 'cancelled',
                'actor_id' => $cancelledBy === 'vendor' ? $this->owner->id : $customer->id, 'created_at' => $requestedAt->copy()->addDays(2),
            ]);
        }
    }

    public function test_response_time_acceptance_and_cancellation_rates_are_computed_nightly(): void
    {
        $this->request(10, 'confirmed');
        $this->request(30, 'confirmed', cancelledBy: 'vendor');
        $this->request(50, 'confirmed', cancelledBy: 'customer');
        $this->request(90, 'confirmed');
        $this->request(20, 'declined');
        $this->request(1440, 'expired');
        $this->request(5, 'declined', source: 'vendor_manual'); // recorded by the vendor: not counted
        $this->request(5, 'declined', monthsAgo: 13);           // too old

        Artisan::call('vendors:trust-signals');

        $vendor = $this->vendor->fresh();
        $this->assertSame(30, $vendor->response_time_minutes, 'median of 10, 20, 30, 50, 90');
        $this->assertSame(0.6667, $vendor->acceptance_rate);
        $this->assertSame(0.25, $vendor->cancellation_rate);
        $this->assertNotNull($vendor->trust_computed_at);

        $this->signIn();
        $this->getJson("/v1/vendors/{$vendor->id}")->assertOk()
            ->assertJsonPath('trust', ['response_time_minutes' => 30, 'acceptance_rate' => 0.6667, 'cancellation_rate' => 0.25]);

        config(['eventapp.trust.show_cancellation_rate_above' => 0.3]);
        $this->assertArrayNotHasKey('cancellation_rate', $this->getJson("/v1/vendors/{$vendor->id}")->json('trust'));
    }

    public function test_too_few_requests_leave_the_signals_empty(): void
    {
        $this->request(10, 'confirmed');
        $this->request(10, 'confirmed', cancelledBy: 'vendor');

        Artisan::call('vendors:trust-signals');

        $this->signIn();
        $this->getJson("/v1/vendors/{$this->vendor->id}")
            ->assertJsonPath('trust', ['response_time_minutes' => null, 'acceptance_rate' => null]);
    }

    public function test_admins_filter_remove_with_a_reason_and_restore_reviews(): void
    {
        $customer = User::factory()->create();
        $booking = Booking::create([
            'number' => Booking::nextNumber(), 'customer_id' => $customer->id, 'vendor_id' => $this->vendor->id,
            'window_start' => now()->subDays(5), 'window_end' => now()->subDays(4), 'fulfilment' => ['type' => 'pickup'],
            'subtotal_minor' => 1000, 'total_minor' => 1000, 'deposit_due_minor' => 0, 'currency' => 'GHS',
            'commission_percent' => 10, 'commission_minor' => 100, 'policy_snapshot' => [], 'status' => 'completed', 'completed_at' => now()->subDay(),
        ]);
        $reported = Review::create([
            'booking_id' => $booking->id, 'direction' => 'customer_to_vendor', 'author_id' => $customer->id, 'vendor_id' => $this->vendor->id,
            'customer_id' => $customer->id, 'rating' => 1, 'body' => 'Spam spam', 'status' => 'published', 'published_at' => now(), 'edited_until' => now(),
        ]);
        $quiet = Review::create([
            'booking_id' => $booking->id, 'direction' => 'vendor_to_customer', 'author_id' => $this->owner->id, 'vendor_id' => $this->vendor->id,
            'customer_id' => $customer->id, 'rating' => 5, 'status' => 'published', 'published_at' => now(), 'edited_until' => now(),
        ]);
        Report::create(['reporter_id' => $this->owner->id, 'target_type' => 'review', 'target_id' => $reported->id, 'reason' => 'spam']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->get('/admin/reviews')->assertOk();

        Livewire::test(ManageReviews::class)
            ->filterTable('reported', true)
            ->assertCanSeeTableRecords([$reported])
            ->assertCanNotSeeTableRecords([$quiet])
            ->callTableAction('remove', $reported, data: ['reason' => null])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame('published', $reported->fresh()->status->value);

        Livewire::test(ManageReviews::class)
            ->callTableAction('remove', $reported, data: ['reason' => 'spam', 'note' => 'Copy-pasted ads'])
            ->assertHasNoTableActionErrors();

        $reported->refresh();
        $this->assertSame(['removed', 'spam', $admin->id], [$reported->status->value, $reported->removed_reason, $reported->removed_by]);

        Livewire::test(ManageReviews::class)->callTableAction('restore', $reported);
        $this->assertSame('published', $reported->fresh()->status->value);
    }
}

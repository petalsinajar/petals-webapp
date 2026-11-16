<?php

namespace Tests\Feature;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** People delete their own account from the app; App Store and Play both require it. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function booking(User $customer, BookingStatus $status): Booking
    {
        $vendor = Vendor::factory()->create();
        $event = Event::factory()->create(['host_id' => $customer->id]);

        return Booking::create([
            'number' => Booking::nextNumber(),
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'window_start' => now()->addDays(9),
            'window_end' => now()->addDays(11),
            'fulfilment' => ['type' => 'delivery'],
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'deposit_due_minor' => 3000,
            'currency' => 'GHS',
            'commission_percent' => 10,
            'commission_minor' => 1000,
            'policy_snapshot' => [],
            'status' => $status,
        ]);
    }

    public function test_deleting_frees_the_phone_and_handle_and_removes_what_they_shared(): void
    {
        $ama = $this->signIn(User::factory()->create(['phone' => '+233241234567', 'handle' => 'ama']));
        $postId = $this->postJson('/v1/posts', ['caption' => 'Setup day', 'media' => [$this->media($ama)->id]])->json('id');
        $kofi = User::factory()->create();
        $this->postJson('/v1/follows', ['target_type' => 'user', 'target_id' => $kofi->id])->assertCreated();
        $planned = Event::factory()->create(['host_id' => $ama->id]);

        $this->signIn($kofi);
        $kofisPost = $this->postJson('/v1/posts', ['media' => [$this->media($kofi)->id]])->json('id');

        $this->signIn($ama);
        $this->putJson("/v1/posts/{$kofisPost}/reaction")->assertOk();
        $this->deleteJson('/v1/me', ['confirm' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->deleteJson('/v1/me', ['confirm' => 'DELETE'])->assertOk();

        $gone = User::withTrashed()->find($ama->id);
        $this->assertSoftDeleted($gone);
        $this->assertSame('deleted', $gone->status);
        $this->assertSame('Former member', $gone->display_name);
        $this->assertNotSame('+233241234567', $gone->phone);
        $this->assertSame(0, $gone->tokens()->count());
        $this->assertDatabaseMissing('handles', ['handle' => 'ama']);
        $this->assertSoftDeleted(Post::withTrashed()->find($postId));
        $this->assertSoftDeleted($planned);
        $this->assertSame(0, $kofi->refresh()->followers_count);
        // Their likes go, and the counts follow.
        $this->assertDatabaseMissing('reactions', ['actor_id' => $ama->id]);
        $this->assertSame(0, Post::find($kofisPost)->likes_count);

        // The same number can sign up again, as someone new.
        $this->app['auth']->forgetGuards();
        $this->postJson('/v1/auth/otp', ['phone' => '0241234567'])->assertOk();
        $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '123456', 'device_name' => 'pixel'])
            ->assertOk()->assertJsonPath('is_new', true);
    }

    public function test_bookings_in_progress_must_finish_first(): void
    {
        $ama = $this->signIn();
        $this->booking($ama, BookingStatus::Confirmed);

        $this->deleteJson('/v1/me', ['confirm' => 'DELETE'])
            ->assertStatus(409)->assertJsonPath('code', 'account_in_use')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'bookings in progress'));
        $this->assertNotSoftDeleted($ama);
    }

    public function test_finished_bookings_are_kept_for_the_records(): void
    {
        $ama = $this->signIn();
        $booking = $this->booking($ama, BookingStatus::Completed);

        $this->deleteJson('/v1/me', ['confirm' => 'DELETE'])->assertOk();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'customer_id' => $ama->id]);
        // Its event stays too: the vendor's records point at it.
        $this->assertNotSoftDeleted($booking->event);
    }

    public function test_a_business_cant_be_left_without_an_owner(): void
    {
        $ama = $this->signIn();
        $vendor = Vendor::factory()->create(['name' => 'Royal Canopies']);
        $vendor->members()->create(['user_id' => $ama->id, 'role' => VendorRole::Owner, 'status' => 'active']);

        $this->deleteJson('/v1/me', ['confirm' => 'DELETE'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'only owner of Royal Canopies'));

        // With a second owner it goes through, and they leave the team.
        $vendor->members()->create(['user_id' => User::factory()->create()->id, 'role' => VendorRole::Owner, 'status' => 'active']);
        $this->deleteJson('/v1/me', ['confirm' => 'DELETE'])->assertOk();
        $this->assertDatabaseHas('vendor_members', ['vendor_id' => $vendor->id, 'user_id' => $ama->id, 'status' => 'removed']);
    }
}

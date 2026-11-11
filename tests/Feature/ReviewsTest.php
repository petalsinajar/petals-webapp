<?php

namespace Tests\Feature;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Catalog\Models\Item;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Notifications\ReviewReceived;
use App\Modules\Reviews\Notifications\ReviewReminder;
use App\Modules\Reviews\Notifications\VendorReplied;
use App\Modules\Reviews\Services\ReviewModeration;
use App\Modules\Reviews\Services\ReviewService;
use App\Modules\Social\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Verified two-way reviews: only completed bookings, one per side, double-blind, public one
 * way and private the other, with replies, reports, moderation and reminders.
 */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private User $customer;

    private Vendor $vendor;

    private Item $chairs;

    private Item $tent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create();
        $this->staff = User::factory()->create();
        $this->vendor->members()->create(['user_id' => $this->staff->id, 'role' => VendorRole::Staff, 'status' => 'active']);
        $this->customer = User::factory()->create();

        $this->chairs = Item::find($this->variant($this->vendor)->itemId);
        $this->tent = Item::find($this->variant($this->vendor, ['title' => 'Tent'], 'tents')->itemId);
    }

    /** A booking with one line per item, completed $daysAgo days ago (or in another status). */
    private function booking(?User $customer = null, float $daysAgo = 2, BookingStatus $status = BookingStatus::Completed, ?Vendor $vendor = null, array $items = []): Booking
    {
        $customer ??= $this->customer;
        $vendor ??= $this->vendor;
        $event = Event::factory()->create(['host_id' => $customer->id, 'type' => 'wedding', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(10)->addHours(6)]);

        $booking = Booking::create([
            'number' => Booking::nextNumber(),
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'window_start' => now()->subDays(11),
            'window_end' => now()->subDays(9),
            'fulfilment' => ['type' => 'delivery'],
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'deposit_due_minor' => 3000,
            'currency' => 'GHS',
            'commission_percent' => 10,
            'commission_minor' => 1000,
            'policy_snapshot' => [],
            'status' => $status,
            'completed_at' => $status === BookingStatus::Completed ? now()->subMinutes((int) ($daysAgo * 1440)) : null,
        ]);

        foreach ($items ?: [$this->chairs, $this->tent] as $item) {
            $booking->lines()->create([
                'item_id' => $item->id,
                'variant_id' => $item->variants()->value('id'),
                'title_snapshot' => $item->title,
                'qty' => 10,
                'unit_price_minor' => 500,
                'line_total_minor' => 5000,
            ]);
        }

        return $booking;
    }

    private function review(Booking $booking, User $as, array $data = []): array
    {
        $this->signIn($as);

        return $this->postJson("/v1/bookings/{$booking->id}/reviews", $data + ['rating' => 5])->assertCreated()->json();
    }

    public function test_reviews_stay_hidden_until_both_sides_have_reviewed_then_publish_together(): void
    {
        $booking = $this->booking();

        $mine = $this->review($booking, $this->customer, [
            'rating' => 4, 'body' => 'Lovely chairs, a bit late.',
            'aspects' => ['quality' => 5, 'punctuality' => 3], 'item_ids' => [$this->chairs->id],
        ]);
        $this->assertSame('pending', $mine['status']);
        $this->assertTrue($mine['verified_booking']);
        $this->assertSame(['event_type' => 'wedding', 'event_date' => now()->subDays(10)->toDateString()], $mine['booking']);
        $this->assertNotNull($mine['editable_until']);

        // The vendor knows a review exists, but not what it says.
        $this->signIn($this->staff);
        $this->getJson("/v1/bookings/{$booking->id}/reviews")->assertOk()
            ->assertJsonPath('direction', 'vendor_to_customer')
            ->assertJsonPath('can_review', true)
            ->assertJsonPath('mine', null)
            ->assertJsonPath('other_side', ['submitted' => true, 'review' => null]);
        $this->getJson("/v1/vendors/{$this->vendor->id}/reviews")->assertJsonCount(0, 'data');
        $this->assertSame(0, $this->vendor->fresh()->rating_count);

        // Any team member can review the customer; that publishes both.
        $this->review($booking, $this->staff, ['rating' => 5, 'aspects' => ['care_of_items' => 5]]);

        $this->assertSame(['published', 'published'], Review::orderBy('direction')->pluck('status')->map->value->all());
        $vendor = $this->vendor->fresh();
        $this->assertSame([4.0, 1], [$vendor->rating_avg, $vendor->rating_count]);
        $this->assertSame([4.0, 1], [$this->chairs->fresh()->rating_avg, $this->chairs->fresh()->rating_count]);
        $this->assertSame(0, $this->tent->fresh()->rating_count, 'only the items reviewed');

        Notification::assertSentTo($this->owner, ReviewReceived::class, fn ($n) => $n->review->direction->value === 'customer_to_vendor');
        Notification::assertSentTo($this->customer, ReviewReceived::class, fn ($n) => $n->toArray($this->customer)['rating'] === null);
        Notification::assertNotSentTo($this->staff, ReviewReceived::class);

        // The vendor now sees the customer's review; the customer never sees the vendor's.
        $this->getJson("/v1/bookings/{$booking->id}/reviews")
            ->assertJsonPath('other_side.review.body', 'Lovely chairs, a bit late.');
        $this->signIn($this->customer);
        $this->getJson("/v1/bookings/{$booking->id}/reviews")
            ->assertJsonPath('mine.status', 'published')
            ->assertJsonPath('can_review', false)
            ->assertJsonPath('other_side', ['submitted' => true, 'review' => null]);
    }

    public function test_only_the_two_sides_of_a_completed_booking_review_once_within_the_window(): void
    {
        $this->signIn($this->customer);
        $this->postJson('/v1/bookings/'.$this->booking(status: BookingStatus::Returned)->id.'/reviews', ['rating' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('booking');
        $this->postJson('/v1/bookings/'.$this->booking(daysAgo: 31)->id.'/reviews', ['rating' => 5])
            ->assertUnprocessable()->assertJsonValidationErrorFor('booking');

        $booking = $this->booking();
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 6])->assertJsonValidationErrors('rating');
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 5, 'aspects' => ['setup' => 5, 'vibes' => 4]])
            ->assertJsonValidationErrors('aspects');
        $other = Item::find($this->variant(Vendor::factory()->create())->itemId);
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 5, 'item_ids' => [$other->id]])
            ->assertJsonValidationErrors('item_ids');

        $all = $this->review($booking, $this->customer);
        $this->assertEqualsCanonicalizing([$this->chairs->id, $this->tent->id], $all['item_ids'], 'every booked item by default');
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 1])->assertJsonValidationErrors('booking');

        $this->signIn(User::factory()->create());
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 1])->assertForbidden();
        $this->getJson("/v1/bookings/{$booking->id}/reviews")->assertForbidden();
    }

    public function test_photos_are_attached_through_the_media_module(): void
    {
        $booking = $this->booking();
        $photo = $this->media($this->customer);
        $someoneElses = $this->media($this->owner);

        $this->signIn($this->customer);
        $this->postJson("/v1/bookings/{$booking->id}/reviews", ['rating' => 5, 'media' => [$someoneElses->id]])
            ->assertJsonValidationErrors('media.0');

        $review = $this->review($booking, $this->customer, ['media' => [$photo->id]]);
        $this->assertSame($photo->id, $review['media'][0]['id']);
        $this->assertSame(['review', $review['id']], [$photo->fresh()->attachable_type, $photo->fresh()->attachable_id]);
    }

    public function test_the_author_edits_for_48_hours_and_only_until_it_is_published(): void
    {
        $booking = $this->booking();
        $review = $this->review($booking, $this->customer, ['rating' => 2]);

        $this->signIn($this->owner);
        $this->patchJson("/v1/reviews/{$review['id']}", ['rating' => 5])->assertForbidden();

        $this->signIn($this->customer);
        $this->patchJson("/v1/reviews/{$review['id']}", ['rating' => 3, 'body' => 'Better on reflection.'])->assertOk()
            ->assertJsonPath('rating', 3)->assertJsonPath('body', 'Better on reflection.');

        $this->travel(49)->hours();
        $this->patchJson("/v1/reviews/{$review['id']}", ['rating' => 4])->assertJsonValidationErrors('review');

        $this->travelBack();
        $second = $this->booking();
        $early = $this->review($second, $this->customer);
        $this->review($second, $this->owner);
        $this->signIn($this->customer);
        $this->patchJson("/v1/reviews/{$early['id']}", ['rating' => 1])->assertJsonValidationErrors('review');
    }

    public function test_the_sweep_publishes_after_the_blind_window_and_reminds_whoever_has_not_reviewed(): void
    {
        $booking = $this->booking(daysAgo: 0.5);
        $this->customer->update(['settings' => ['notify' => ['reviews' => false]]]);

        Artisan::call('reviews:sweep');
        Notification::assertNothingSent();

        // Day 1: the vendor's managers are reminded; the customer turned review notices off.
        $this->travel(1)->days();
        Artisan::call('reviews:sweep');
        Artisan::call('reviews:sweep');
        Notification::assertSentToTimes($this->owner, ReviewReminder::class, 1);
        Notification::assertNotSentTo([$this->customer, $this->staff], ReviewReminder::class);

        // The vendor reviews; 14 days on, nobody else did, so it's published alone.
        $review = $this->review($booking, $this->owner, ['rating' => 3]);
        $this->travel(7)->days();
        Artisan::call('reviews:sweep');
        Notification::assertSentToTimes($this->owner, ReviewReminder::class, 1);
        $this->assertSame('pending', Review::find($review['id'])->status->value);

        $this->travel(8)->days();
        Artisan::call('reviews:sweep');
        $this->assertSame('published', Review::find($review['id'])->status->value);
        Notification::assertNotSentTo($this->customer, ReviewReceived::class);
    }

    public function test_reminders_go_out_at_day_1_and_day_7_but_a_missed_run_sends_one(): void
    {
        $this->booking(daysAgo: 0.5);
        $late = $this->booking(daysAgo: 8);

        $this->travel(1)->days();
        Artisan::call('reviews:sweep');
        $this->travel(6)->days();
        Artisan::call('reviews:sweep');

        Notification::assertSentToTimes($this->customer, ReviewReminder::class, 3);
        $stages = Notification::sent($this->customer, ReviewReminder::class)
            ->groupBy(fn ($n) => $n->booking->id)->map(fn ($ns) => $ns->pluck('stageDays')->sort()->values()->all());
        $this->assertSame([7], $stages[$late->id]);
    }

    public function test_vendor_reviews_are_listed_with_a_summary_sorts_and_filters(): void
    {
        $other = User::factory()->create();
        foreach ([[5, ['quality' => 5, 'setup' => 4], true], [2, ['quality' => 2], false], [4, null, false]] as $i => [$rating, $aspects, $photo]) {
            $customer = $i === 0 ? $this->customer : User::factory()->create();
            $booking = $this->booking($customer);
            $this->review($booking, $customer, ['rating' => $rating, 'aspects' => $aspects, 'media' => $photo ? [$this->media($customer)->id] : []]);
            $this->review($booking, $this->owner);
        }
        $this->review($this->booking($other), $other, ['rating' => 1]); // still pending: not listed

        $this->signIn($other);
        $response = $this->getJson("/v1/vendors/{$this->vendor->id}/reviews")->assertOk();
        $this->assertSame(['average' => 3.67, 'count' => 3, 'stars' => ['5' => 1, '4' => 1, '3' => 0, '2' => 1, '1' => 0],
            'aspects' => ['quality' => 3.5, 'punctuality' => null, 'setup' => 4, 'communication' => null]], $response->json('summary'));
        $this->assertCount(3, $response->json('data'));
        $this->assertSame([3.67, 3], [$this->vendor->fresh()->rating_avg, $this->vendor->fresh()->rating_count]);

        $this->assertSame([5, 4, 2], array_column($this->getJson("/v1/vendors/{$this->vendor->id}/reviews?sort=highest")->json('data'), 'rating'));
        $this->assertSame([2, 4, 5], array_column($this->getJson("/v1/vendors/{$this->vendor->id}/reviews?sort=lowest")->json('data'), 'rating'));
        $this->assertSame([2], array_column($this->getJson("/v1/vendors/{$this->vendor->id}/reviews?rating=2")->json('data'), 'rating'));
        $this->getJson("/v1/vendors/{$this->vendor->id}/reviews?with_photos=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 5);

        $this->getJson("/v1/items/{$this->tent->id}/reviews")->assertJsonCount(3, 'data');
    }

    public function test_reviews_page_with_a_cursor(): void
    {
        foreach (range(1, 21) as $i) {
            $customer = User::factory()->create();
            $booking = $this->booking($customer, items: [$this->chairs]);
            app(ReviewService::class)->submit($booking, $customer, ['rating' => $i % 5 + 1]);
            app(ReviewService::class)->submit($booking, $this->owner, ['rating' => 5]);
        }
        $this->signIn($this->customer);

        $first = $this->getJson("/v1/items/{$this->chairs->id}/reviews?sort=highest")->assertJsonCount(20, 'data');
        $second = $this->getJson("/v1/items/{$this->chairs->id}/reviews?sort=highest&cursor=".$first->json('next_cursor'))
            ->assertJsonCount(1, 'data')->assertJsonPath('summary', null)->assertJsonPath('next_cursor', null);
        $this->assertSame(1, $second->json('data.0.rating'));
        $this->getJson("/v1/items/{$this->tent->id}/reviews")->assertJsonCount(0, 'data');
    }

    public function test_the_vendor_replies_once_publicly_and_can_edit_it_for_48_hours(): void
    {
        $booking = $this->booking();
        $review = $this->review($booking, $this->customer, ['rating' => 3]);

        $this->signIn($this->owner);
        $this->postJson("/v1/reviews/{$review['id']}/reply", ['body' => 'Thanks!'])->assertJsonValidationErrors('review'); // not published yet
        $this->review($booking, $this->owner);

        $this->signIn($this->staff);
        $this->postJson("/v1/reviews/{$review['id']}/reply", ['body' => 'Thanks!'])->assertForbidden();

        $this->signIn($this->owner);
        $this->postJson("/v1/reviews/{$review['id']}/reply", ['body' => 'Thanks for booking!'])->assertOk()
            ->assertJsonPath('vendor_reply.body', 'Thanks for booking!');
        $this->postJson("/v1/reviews/{$review['id']}/reply", ['body' => 'Thanks for booking with us!'])->assertOk();
        Notification::assertSentToTimes($this->customer, VendorReplied::class, 1);

        $this->travel(49)->hours();
        $this->postJson("/v1/reviews/{$review['id']}/reply", ['body' => 'Edited later'])->assertJsonValidationErrors('body');

        $this->signIn(User::factory()->create());
        $this->getJson("/v1/vendors/{$this->vendor->id}/reviews")->assertJsonPath('data.0.vendor_reply.body', 'Thanks for booking with us!');
    }

    public function test_reports_feed_the_queue_and_removal_keeps_the_record_and_recomputes_ratings(): void
    {
        $booking = $this->booking();
        $review = $this->review($booking, $this->customer, ['rating' => 1, 'body' => 'Call him on 024...']);
        $this->review($booking, $this->owner);
        $this->assertSame(1, $this->vendor->fresh()->rating_count);

        $this->signIn($this->owner);
        $this->postJson("/v1/reviews/{$review['id']}/report", ['reason' => 'too_negative'])->assertJsonValidationErrors('reason');
        $this->postJson("/v1/reviews/{$review['id']}/report", ['reason' => 'private_details'])->assertCreated();
        $this->postJson("/v1/reviews/{$review['id']}/report", ['reason' => 'private_details'])->assertOk();
        $this->assertSame(1, Report::where(['target_type' => 'review', 'target_id' => $review['id'], 'status' => 'open'])->count());

        $model = Review::find($review['id']);
        app(ReviewModeration::class)->remove($model, User::factory()->admin()->create(), 'private_details', 'Phone number');

        $model->refresh();
        $this->assertSame(['removed', 'private_details', 'Phone number'], [$model->status->value, $model->removed_reason, $model->removed_note]);
        $this->assertSame(0, $this->vendor->fresh()->rating_count);
        $this->assertSame(0, $this->chairs->fresh()->rating_count);
        $this->assertSame('actioned', Report::first()->status);
        $this->getJson("/v1/vendors/{$this->vendor->id}/reviews")->assertJsonCount(0, 'data');

        app(ReviewModeration::class)->restore($model);
        $this->assertSame('published', $model->fresh()->status->value);
        $this->assertSame([1.0, 1], [$this->vendor->fresh()->rating_avg, $this->vendor->fresh()->rating_count]);
    }

    public function test_vendor_reviews_of_a_customer_are_only_shown_to_vendors_the_customer_is_booking(): void
    {
        $booking = $this->booking();
        $this->review($booking, $this->customer);
        $this->review($booking, $this->staff, ['rating' => 2, 'body' => 'Returned the tent wet.', 'aspects' => ['on_time_return' => 2]]);

        $otherOwner = User::factory()->create();
        $other = Vendor::factory()->ownedBy($otherOwner)->create();

        $this->signIn($otherOwner);
        $this->getJson("/v1/customers/{$this->customer->id}/vendor-reviews")->assertForbidden();

        $this->booking(status: BookingStatus::PendingVendor, vendor: $other, items: [Item::find($this->variant($other)->itemId)]);
        $this->getJson("/v1/customers/{$this->customer->id}/vendor-reviews")->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.aspects.on_time_return', 2)
            ->assertJsonPath('data.0.body', 'Returned the tent wet.')
            ->assertJsonPath('data.0.author.type', 'vendor')
            ->assertJsonPath('data.0.verified_booking', true);

        // Never to the customer, and not on their side of the booking.
        $this->signIn($this->customer);
        $this->getJson("/v1/customers/{$this->customer->id}/vendor-reviews")->assertForbidden();
    }
}

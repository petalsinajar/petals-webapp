<?php

namespace Tests\Feature;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingRequested;
use App\Modules\Bookings\Notifications\BookingStatusChanged;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Modules\Recommendations\Notifications\VendorSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Notifications also reach the user's phone through OneSignal. */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'eventapp.push.onesignal.app_id' => 'app-123',
            'eventapp.push.onesignal.rest_api_key' => 'rest-key',
        ]);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
    }

    /** $author posts; $liker likes it, which notifies $author. */
    private function like(User $author, User $liker): void
    {
        Sanctum::actingAs($author, ['access']);
        $postId = $this->postJson('/v1/posts', [
            'caption' => 'Our setup',
            'visibility' => 'public',
            'media' => [['url' => 'https://example.com/a.jpg', 'type' => 'image']],
        ])->assertCreated()->json('id');

        Sanctum::actingAs($liker, ['access']);
        $this->putJson("/v1/posts/{$postId}/reaction")->assertOk();
    }

    public function test_a_like_pushes_to_the_author_by_their_account_id(): void
    {
        $author = User::factory()->create();
        $liker = User::factory()->create(['display_name' => 'Efua Owusu']);

        $this->like($author, $liker);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.onesignal.com/notifications'
            && $r->hasHeader('Authorization', 'Key rest-key')
            && $r['app_id'] === 'app-123'
            && $r['target_channel'] === 'push'
            && $r['include_aliases'] === ['external_id' => [$author->id]]
            && $r['headings'] === ['en' => 'New like']
            && $r['contents'] === ['en' => 'Efua Owusu liked your post.']);
        $this->assertSame(1, $author->notifications()->count(), 'still in the in-app list');
    }

    public function test_the_push_switch_in_settings_is_respected(): void
    {
        $author = User::factory()->create(['settings' => ['notify' => ['push' => false]]]);

        $this->like($author, User::factory()->create());

        Http::assertNothingSent();
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_without_a_rest_api_key_push_is_skipped_quietly(): void
    {
        config(['eventapp.push.onesignal.rest_api_key' => null]);
        $author = User::factory()->create();

        $this->like($author, User::factory()->create());

        Http::assertNothingSent();
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_a_onesignal_error_never_breaks_the_in_app_notification(): void
    {
        Http::fake(['api.onesignal.com/*' => Http::response(['errors' => ['App not found']], 400)]);
        $author = User::factory()->create();

        $this->like($author, User::factory()->create());

        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_a_new_follower_is_pushed(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->create(['display_name' => 'Kojo Addo']), ['access']);

        $this->postJson('/v1/follows', ['target_type' => 'user', 'target_id' => $target->id])->assertCreated();

        Http::assertSent(fn (Request $r) => $r['include_aliases'] === ['external_id' => [$target->id]]
            && $r['contents'] === ['en' => 'Kojo Addo started following you.']);
    }

    public function test_vendor_suggestions_push_opens_the_event(): void
    {
        $event = Event::factory()->create();
        $notification = new VendorSuggestions($event, 'tents', RecommendationReason::MissingCategory, [
            ['id' => 'v1', 'name' => 'Royal Canopies'],
        ]);

        $push = $notification->toPush($event->host);

        $this->assertSame($notification->title(), $push->title);
        $this->assertSame($notification->body(), $push->body);
        $this->assertSame("/events/{$event->id}", $push->route);
        $this->assertContains('push', $notification->via($event->host));
    }

    /** An unsaved booking is enough: the push only reads its fields. */
    private function booking(User $customer): Booking
    {
        return (new Booking)->forceFill([
            'id' => '01JBOOKING0000000000000000',
            'number' => 'PIJ-1042',
            'vendor_id' => '01JVENDOR00000000000000000',
            'customer_id' => $customer->id,
            'event_id' => '01JEVENT000000000000000000',
            'status' => BookingStatus::PendingVendor,
        ]);
    }

    public function test_a_booking_request_push_opens_that_businesss_requests(): void
    {
        $customer = User::factory()->create(['display_name' => 'Kwame Boateng']);
        $manager = User::factory()->create();

        $push = (new BookingRequested($this->booking($customer)))->toPush($manager);

        $this->assertSame('New booking request', $push->title);
        $this->assertStringContainsString('PIJ-1042', $push->body);
        $this->assertSame('/vendor/requests?vendor=01JVENDOR00000000000000000', $push->route);
    }

    public function test_booking_updates_open_the_event_for_customers_and_requests_for_vendors(): void
    {
        $customer = User::factory()->create();
        $booking = $this->booking($customer)->forceFill(['status' => BookingStatus::Accepted]);
        $notification = new BookingStatusChanged($booking);

        $this->assertSame('/events/01JEVENT000000000000000000', $notification->toPush($customer)->route);
        $this->assertSame(
            '/vendor/requests?vendor=01JVENDOR00000000000000000',
            $notification->toPush(User::factory()->create())->route,
        );
    }
}

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
use App\Support\Push\OneSignalChannel;
use App\Support\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Notifications also reach the user's phone through OneSignal. */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** OneSignal's reply to the next push; tests change it. */
    private array $reply = ['id' => 'n1'];

    private int $replyStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'eventapp.push.onesignal.app_id' => 'app-123',
            'eventapp.push.onesignal.rest_api_key' => 'os_v2_app_rest-key',
        ]);
        Http::fake(['api.onesignal.com/*' => fn () => Http::response($this->reply, $this->replyStatus)]);
    }

    /** $author posts; $liker likes it, which notifies $author. */
    private function like(User $author, User $liker): void
    {
        Sanctum::actingAs($author, ['access']);
        $postId = $this->postJson('/v1/posts', [
            'caption' => 'Our setup',
            'visibility' => 'public',
            'media' => [$this->media($author)->id],
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
            && $r->hasHeader('Authorization', 'Key os_v2_app_rest-key')
            && $r['app_id'] === 'app-123'
            && $r['target_channel'] === 'push'
            && $r['include_aliases'] === ['external_id' => [$author->id]]
            && $r['headings'] === ['en' => 'New like']
            && $r['contents'] === ['en' => 'Efua Owusu liked your post.']);
        $this->assertSame(1, $author->notifications()->count(), 'still in the in-app list');
    }

    public function test_legacy_keys_are_sent_as_basic_and_env_quotes_are_trimmed(): void
    {
        config(['eventapp.push.onesignal.rest_api_key' => ' "legacyKey123" ']);

        $this->like(User::factory()->create(), User::factory()->create());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic legacyKey123'));
    }

    public function test_the_push_switch_in_settings_is_respected(): void
    {
        $author = User::factory()->create(['settings' => ['notify' => ['push' => false]]]);

        $this->like($author, User::factory()->create());

        Http::assertNothingSent();
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_without_a_rest_api_key_push_is_skipped_and_logged(): void
    {
        config(['eventapp.push.onesignal.rest_api_key' => null]);
        Log::spy();
        $author = User::factory()->create();

        $this->like($author, User::factory()->create());

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'ONESIGNAL_REST_API_KEY'));
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_a_onesignal_error_never_breaks_the_in_app_notification(): void
    {
        [$this->reply, $this->replyStatus] = [['errors' => ['App not found']], 400];
        Log::spy();
        $author = User::factory()->create();

        $this->like($author, User::factory()->create());

        $this->assertSame(1, $author->notifications()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'OneSignal rejected push');
    }

    public function test_a_push_that_reaches_no_device_is_logged(): void
    {
        // What OneSignal says when nobody is signed in on a phone as that user.
        $this->reply = ['id' => '', 'errors' => ['invalid_aliases' => ['external_id' => ['x']]]];
        Log::spy();
        $author = User::factory()->create();

        $this->like($author, User::factory()->create());

        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $context = []) => $m === 'Push reached no device' && $context['user'] === $author->id);
    }

    public function test_push_test_command_explains_what_happened(): void
    {
        $user = User::factory()->create(['handle' => 'ama']);

        $this->artisan('push:test', ['user' => '@ama'])
            ->expectsOutputToContain('Sent (OneSignal id n1)')
            ->assertSuccessful();

        $this->reply = ['id' => '', 'errors' => ['invalid_aliases' => ['external_id' => [$user->id]]]];
        $this->artisan('push:test', ['user' => $user->id])
            ->expectsOutputToContain('no phone is linked to this user')
            ->assertFailed();

        config(['eventapp.push.onesignal.rest_api_key' => null]);
        $this->artisan('push:test', ['user' => $user->id])
            ->expectsOutputToContain('ONESIGNAL_REST_API_KEY is not set')
            ->assertFailed();
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

    public function test_booking_updates_open_the_booking_for_customers_and_requests_for_vendors(): void
    {
        $customer = User::factory()->create();
        $booking = $this->booking($customer)->forceFill(['status' => BookingStatus::Confirmed]);
        $notification = new BookingStatusChanged($booking);

        $push = $notification->toPush($customer);
        $this->assertSame('/bookings/01JBOOKING0000000000000000', $push->route);
        $this->assertStringContainsString('accepted your booking', $push->body);
        $this->assertSame(
            '/vendor/requests?vendor=01JVENDOR00000000000000000',
            $notification->toPush(User::factory()->create())->route,
        );
    }

    public function test_pushes_to_a_business_use_the_vendor_colors_and_customers_the_default(): void
    {
        $customer = User::factory()->create();
        $manager = User::factory()->create();
        $booking = $this->booking($customer)->forceFill(['status' => BookingStatus::Confirmed]);

        $this->assertTrue((new BookingRequested($booking))->toPush($manager)->forBusiness);
        $this->assertTrue((new BookingStatusChanged($booking))->toPush($manager)->forBusiness);
        $this->assertFalse((new BookingStatusChanged($booking))->toPush($customer)->forBusiness);

        $channel = app(OneSignalChannel::class);
        $channel->post($manager->id, new PushMessage('New booking request', 'Respond soon', forBusiness: true));
        $channel->post($customer->id, new PushMessage('Booking confirmed', 'See you there'));

        Http::assertSent(fn (Request $r) => $r['headings'] === ['en' => 'New booking request']
            && $r['android_accent_color'] === 'FF0E7F73'
            && $r['large_icon'] === asset('images/push/business-logo.png'));
        Http::assertSent(fn (Request $r) => $r['headings'] === ['en' => 'Booking confirmed']
            && ! isset($r['android_accent_color'])
            && ! isset($r['large_icon']));
        $this->assertFileExists(public_path('images/push/business-logo.png'));
    }
}

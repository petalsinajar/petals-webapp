<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Admin\Resources\CampaignSegments\Pages\ManageCampaignSegments;
use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Campaigns\Channels\FakePushProvider;
use App\Modules\Campaigns\Channels\FakeSmsProvider;
use App\Modules\Campaigns\Contracts\PushProvider;
use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Data\AudienceMember;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignRecipient;
use App\Modules\Campaigns\Models\CampaignSegment;
use App\Modules\Campaigns\Models\NotificationPreference;
use App\Modules\Campaigns\Models\SmsOptOut;
use App\Modules\Campaigns\Services\AudienceCsv;
use App\Modules\Campaigns\Services\CampaignDelivery;
use App\Modules\Campaigns\Services\CampaignDispatcher;
use App\Modules\Campaigns\Services\CampaignReport;
use App\Modules\Campaigns\Services\CampaignService;
use App\Modules\Campaigns\Services\LifecycleAutomations;
use App\Modules\Campaigns\Services\SegmentQuery;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Follow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin campaigns: audiences, opt-outs, caps, quiet hours, approval, delivery and reporting. */
class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    /** Monday 10:00 in Accra (UTC). */
    private const NOW = '2026-11-23 10:00:00';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::NOW));
        $this->author = User::factory()->admin(['campaigns'])->create();
    }

    // ---- Audiences ------------------------------------------------------

    public function test_segments_filter_by_role_and_vendor_plan(): void
    {
        $host = $this->person();
        Event::factory()->create(['host_id' => $host->id, 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(5)]);
        $guest = $this->person();
        $party = Event::factory()->create(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(3)]);
        $party->members()->create(['user_id' => $guest->id, 'role' => 'guest', 'rsvp' => 'going']);
        $starterOwner = $this->person();
        Vendor::factory()->ownedBy($starterOwner)->create();
        $growthOwner = $this->person();
        Vendor::factory()->ownedBy($growthOwner)->onPlan('growth')->create();
        $nobody = $this->person();
        User::factory()->admin()->create(); // admins are never in an audience

        $this->assertAudience([$host, $party->host], ['roles' => ['host']]);
        $this->assertAudience([$guest], ['roles' => ['guest']]);
        $this->assertAudience([$starterOwner, $growthOwner], ['roles' => ['vendor']]);
        $this->assertAudience([$growthOwner], ['roles' => ['vendor'], 'vendor_plans' => ['growth']]);
        $this->assertAudience([$starterOwner], ['roles' => ['vendor'], 'vendor_plans' => ['starter']]);
        $this->assertAudience([$host, $party->host, $guest], ['roles' => ['host', 'guest']]);
        $this->assertAudience([$host, $guest, $starterOwner, $growthOwner, $nobody, $party->host], []);
    }

    public function test_segments_filter_by_place_and_activity(): void
    {
        $accra = $this->person(['city' => 'Accra', 'lat' => 5.6037, 'lng' => -0.1870, 'created_at' => now()->subDays(40), 'last_active_at' => now()->subDays(20)]);
        $tema = $this->person(['city' => 'Tema', 'lat' => 5.6698, 'lng' => -0.0166, 'created_at' => now()->subDays(3), 'last_active_at' => now()->subHour()]);
        $kumasi = $this->person(['city' => 'Kumasi', 'lat' => 6.6885, 'lng' => -1.6244, 'created_at' => now()->subDays(10), 'last_active_at' => now()->subDays(2)]);

        $this->assertAudience([$accra], ['city' => ' accra ']);
        // Tema is ~19 km from central Accra, Kumasi ~200.
        $this->assertAudience([$accra, $tema], ['near' => ['lat' => 5.6037, 'lng' => -0.1870, 'km' => 25]]);
        $this->assertAudience([$accra], ['near' => ['lat' => 5.6037, 'lng' => -0.1870, 'km' => 5]]);
        $this->assertAudience([$tema, $kumasi], ['signed_up_from' => now()->subDays(14)->toDateString()]);
        $this->assertAudience([$accra, $kumasi], ['signed_up_to' => now()->subDays(10)->toDateString()]);
        $this->assertAudience([$accra], ['last_active_before' => now()->subDays(14)->toDateString()]);
        $this->assertAudience([$tema, $kumasi], ['last_active_after' => now()->subDays(7)->toDateString()]);
    }

    public function test_segments_filter_by_events_bookings_follows_and_platform(): void
    {
        $bride = $this->person();
        Event::factory()->create(['host_id' => $bride->id, 'type' => 'wedding', 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(6)]);
        $planner = $this->person();
        Event::factory()->create(['host_id' => $planner->id, 'type' => 'corporate', 'starts_at' => now()->addDays(90), 'ends_at' => now()->addDays(90)->addHours(4)]);
        $past = $this->person();
        Event::factory()->create(['host_id' => $past->id, 'type' => 'wedding', 'starts_at' => now()->subDays(30), 'ends_at' => now()->subDays(30)->addHours(6)]);

        $this->assertAudience([$bride, $planner], ['upcoming_event' => []]);
        $this->assertAudience([$bride], ['upcoming_event' => ['types' => ['wedding']]]);
        $this->assertAudience([$bride], ['upcoming_event' => ['to' => now()->addDays(60)->toDateString()]]);
        $this->assertAudience([$bride, $past], ['event_types' => ['wedding']]);

        $vendor = Vendor::factory()->ownedBy()->create();
        $customer = $this->person();
        $this->booking($customer, $vendor);
        $this->assertAudience([$customer], ['has_booked' => true, 'user_ids' => [$customer->id, $bride->id]]);
        $this->assertAudience([$bride], ['has_booked' => false, 'user_ids' => [$customer->id, $bride->id]]);

        Follow::create(['follower_type' => 'user', 'follower_id' => $planner->id, 'target_type' => 'vendor', 'target_id' => $vendor->id]);
        $this->assertAudience([$planner], ['follows_vendor_id' => $vendor->id]);

        Device::create(['user_id' => $bride->id, 'platform' => 'ios', 'push_token' => 'ios-1']);
        Device::create(['user_id' => $planner->id, 'platform' => 'android', 'push_token' => 'and-1']);
        $this->assertAudience([$bride], ['platforms' => ['ios']]);
    }

    public function test_a_csv_matches_existing_users_by_id_or_phone(): void
    {
        $byId = $this->person();
        $byPhone = $this->person(['phone' => '+233241112222']);

        $match = AudienceCsv::match("user\n".strtoupper($byId->id)."\n0241112222\n0249999999\nnot-a-user\n");

        $this->assertEqualsCanonicalizing([$byId->id, $byPhone->id], $match['user_ids']);
        $this->assertSame(5, $match['rows']);
        $this->assertSame(3, $match['unmatched']);
    }

    // ---- Who gets it ----------------------------------------------------

    public function test_opted_out_suspended_and_unreachable_people_are_skipped_with_the_reason(): void
    {
        $ok = $this->person();
        $pushOff = $this->person();
        NotificationPreference::create(['user_id' => $pushOff->id, 'channel' => 'push', 'category' => 'promotions', 'enabled' => false]);
        $allPushOff = $this->person(['settings' => ['notify' => ['push' => false]]]);
        $noDevice = $this->person(device: false);
        $unverified = $this->person(['phone_verified_at' => null]);
        $stopped = $this->person();
        SmsOptOut::create(['phone' => $stopped->phone, 'source' => 'stop_reply']);
        $suspended = $this->person(['status' => 'suspended']);

        $campaign = $this->campaign(['channels' => ['push', 'sms']], ['user_ids' => User::pluck('id')->all()]);
        $this->service()->submit($campaign, $this->author);

        $this->assertNotContains($suspended->id, CampaignRecipient::pluck('user_id')->all(), 'suspended users are never in an audience');
        $this->assertStatus($ok, 'push', RecipientStatus::Sent);
        $this->assertStatus($ok, 'sms', RecipientStatus::Sent);
        $this->assertSkipped($pushOff, 'push', SkipReason::OptedOut);
        $this->assertStatus($pushOff, 'sms', RecipientStatus::Sent);
        $this->assertSkipped($allPushOff, 'push', SkipReason::OptedOut);
        $this->assertSkipped($noDevice, 'push', SkipReason::NoDevice);
        $this->assertSkipped($unverified, 'sms', SkipReason::NoVerifiedPhone);
        $this->assertSkipped($stopped, 'sms', SkipReason::SmsStopped);
        $this->assertStatus($stopped, 'push', RecipientStatus::Sent);

        $this->assertNotContains($pushOff->id, $this->push()->recipients());
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_someone_suspended_after_the_audience_was_built_is_skipped_at_delivery(): void
    {
        $user = $this->person();
        $campaign = $this->campaign();
        app(CampaignDispatcher::class)->run($campaign->forceFill(['channels' => []]), [new AudienceMember($user->id)]); // nothing queued yet
        $row = CampaignRecipient::create(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'channel' => 'push']);
        $user->forceFill(['status' => 'suspended'])->save();

        app(CampaignDelivery::class)->deliver($campaign, Channel::Push, [$row->id]);

        $this->assertSame(SkipReason::Inactive, $row->fresh()->skip_reason);
        $this->assertSame([], $this->push()->sent);
    }

    public function test_messages_are_personalised_and_marketing_sms_get_the_opt_out_line(): void
    {
        $ama = $this->person(['display_name' => 'Ama Mensah', 'city' => 'Kumasi']);
        Event::factory()->create(['host_id' => $ama->id, 'title' => 'Ama turns 30', 'starts_at' => now()->addDays(9), 'ends_at' => now()->addDays(9)->addHours(4)]);
        $campaign = $this->campaign([
            'channels' => ['push', 'sms', 'in_app'],
            'title' => 'Hi {first_name}',
            'body' => 'Vendors near {city} for {event_title}.',
            'deep_link' => ['type' => 'discover'],
        ], ['user_ids' => [$ama->id]]);

        $this->service()->submit($campaign, $this->author);

        $push = $this->push()->sent[0]['message'];
        $this->assertSame('Hi Ama', $push->title);
        $this->assertSame('Vendors near Kumasi for Ama turns 30.', $push->body);
        $this->assertSame('/discover', $push->route);
        $this->assertSame("Vendors near Kumasi for Ama turns 30.\nReply STOP to opt out.", $this->sms()->sent[0]['message']);

        $this->signIn($ama);
        $this->getJson('/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.type', 'campaign')
            ->assertJsonPath('data.0.title', 'Hi Ama')
            ->assertJsonPath('data.0.route', '/discover')
            ->assertJsonPath('data.0.recipient_id', CampaignRecipient::where('channel', 'in_app')->value('id'));
    }

    public function test_identical_pushes_go_out_in_batches(): void
    {
        config(['eventapp.campaigns.push_batch_size' => 2]);
        $people = collect(range(1, 5))->map(fn () => $this->person());
        $campaign = $this->campaign(['title' => 'New this week', 'body' => 'See what’s new.'], ['user_ids' => $people->pluck('id')->all()]);

        $this->service()->submit($campaign, $this->author);

        $this->assertSame([2, 2, 1], array_map(fn ($s) => count($s['user_ids']), $this->push()->sent));
        $this->assertEqualsCanonicalizing($people->pluck('id')->all(), $this->push()->recipients());
    }

    public function test_devices_the_push_provider_no_longer_knows_are_removed(): void
    {
        $gone = $this->person();
        $here = $this->person();
        $this->push()->unreachable = [$gone->id];

        $this->service()->submit($this->campaign([], ['user_ids' => [$gone->id, $here->id]]), $this->author);

        $this->assertStatus($gone, 'push', RecipientStatus::Failed);
        $this->assertStatus($here, 'push', RecipientStatus::Sent);
        $this->assertFalse(Device::where('user_id', $gone->id)->exists());
        $this->assertTrue(Device::where('user_id', $here->id)->exists());
    }

    // ---- Frequency caps and quiet hours ---------------------------------

    public function test_frequency_caps_count_campaigns_per_day_and_per_week(): void
    {
        config(['eventapp.campaigns.frequency_caps' => ['per_day' => 1, 'per_week' => 3]]);
        $user = $this->person();
        $send = fn () => $this->service()->submit($this->campaign(['channels' => ['push', 'in_app']], ['user_ids' => [$user->id]]), $this->author);

        $send();
        // Both channels of one campaign: one campaign towards the cap.
        $this->assertSame(2, CampaignRecipient::where('user_id', $user->id)->whereIn('status', ['sent', 'delivered'])->count());

        $send(); // same day
        $this->assertSame(2, CampaignRecipient::where('skip_reason', SkipReason::FrequencyCap->value)->count());

        foreach ([1, 2] as $day) {
            $this->travelTo(CarbonImmutable::parse(self::NOW)->addDays($day));
            $send();
        }
        $this->assertSame(3, CampaignRecipient::where('user_id', $user->id)->where('channel', 'push')->where('status', 'sent')->count());

        $this->travelTo(CarbonImmutable::parse(self::NOW)->addDays(3));
        $send(); // a fourth in 7 days
        $this->assertSame(4, CampaignRecipient::where('skip_reason', SkipReason::FrequencyCap->value)->count());

        $this->travelTo(CarbonImmutable::parse(self::NOW)->addDays(8));
        $send(); // the first ones are more than a week old
        $this->assertSame(4, CampaignRecipient::where('user_id', $user->id)->where('channel', 'push')->where('status', 'sent')->count());
    }

    public function test_send_now_during_quiet_hours_waits_for_the_morning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 22:30:00'));
        $user = $this->person();

        $campaign = $this->service()->submit($this->campaign([], ['user_ids' => [$user->id]]), $this->author);

        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-24 08:00:00'), $campaign->scheduled_at);
        $this->assertSame([], $this->push()->sent);

        $this->travelTo(CarbonImmutable::parse('2026-11-24 08:00:00'));
        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame([$user->id], $this->push()->recipients());
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_a_schedule_inside_quiet_hours_is_refused(): void
    {
        $campaign = $this->campaign();

        try {
            $this->service()->submit($campaign, $this->author, CarbonImmutable::parse('2026-11-24 06:30:00'));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scheduled_at', $e->errors());
        }
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
    }

    // ---- Scheduling -----------------------------------------------------

    public function test_scheduled_campaigns_go_out_when_due(): void
    {
        $user = $this->person();
        $campaign = $this->service()->submit($this->campaign([], ['user_ids' => [$user->id]]), $this->author, CarbonImmutable::parse('2026-11-23 15:00:00'));

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame([], $this->push()->sent, 'not due yet');

        $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:30'));
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame([$user->id], $this->push()->recipients(), 'sent once');
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_recurring_campaigns_run_again_and_reach_people_once_per_run(): void
    {
        config(['eventapp.campaigns.frequency_caps' => ['per_day' => 5, 'per_week' => 10]]);
        $user = $this->person();
        $campaign = $this->campaign(['recurrence' => 'daily', 'channels' => ['in_app']], ['user_ids' => [$user->id]]);
        $this->service()->submit($campaign, $this->author, CarbonImmutable::parse('2026-11-23 12:00:00'));

        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00'));
        $this->artisan('campaigns:dispatch');
        $campaign->refresh();
        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-24 12:00:00'), $campaign->scheduled_at);

        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->artisan('campaigns:dispatch');

        $this->assertSame(['2026-11-23', '2026-11-24'], CampaignRecipient::orderBy('run_key')->pluck('run_key')->all());
        $this->assertSame(2, $user->notifications()->count());
    }

    // ---- Approval -------------------------------------------------------

    public function test_large_sends_wait_for_a_second_super_admin(): void
    {
        config(['eventapp.campaigns.approval' => ['audience_over' => 2, 'sms_over' => 100]]);
        $people = collect(range(1, 3))->map(fn () => $this->person());
        $super = User::factory()->superAdmin()->create();

        $campaign = $this->service()->submit($this->campaign(['created_by' => $super->id], ['user_ids' => $people->pluck('id')->all()]), $super);
        $this->assertSame(CampaignStatus::PendingApproval, $campaign->status);
        $this->assertTrue($campaign->requires_approval);
        $this->assertSame(3, $campaign->audience_count);
        $this->assertSame([], $this->push()->sent);

        $this->expectRefusal(fn () => $this->service()->approve($campaign, $this->author), 'not a super admin');
        $this->expectRefusal(fn () => $this->service()->approve($campaign, $super), 'the author can’t approve their own');

        $this->service()->approve($campaign, User::factory()->superAdmin()->create());

        $this->assertCount(3, $this->push()->recipients());
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_any_sms_send_over_its_limit_needs_approval(): void
    {
        config(['eventapp.campaigns.approval' => ['audience_over' => 1000, 'sms_over' => 1]]);
        $ids = [$this->person()->id, $this->person()->id];

        $this->assertSame(CampaignStatus::Sent, $this->service()->submit($this->campaign(['channels' => ['push']], ['user_ids' => $ids]), $this->author)->status);
        $smsCampaign = $this->service()->submit($this->campaign(['channels' => ['sms']], ['user_ids' => $ids]), $this->author);

        $this->assertSame(CampaignStatus::PendingApproval, $smsCampaign->status);
    }

    // ---- Idempotency and retries ----------------------------------------

    public function test_running_a_campaign_again_never_sends_twice(): void
    {
        $user = $this->person();
        $campaign = $this->service()->submit($this->campaign(['channels' => ['push', 'sms']], ['user_ids' => [$user->id]]), $this->author);

        $campaign->forceFill(['status' => CampaignStatus::Sending])->save();
        $this->service()->run($campaign);
        app(CampaignDispatcher::class)->run($campaign, [new AudienceMember($user->id), new AudienceMember($user->id)]);

        $this->assertSame(2, CampaignRecipient::count());
        $this->assertCount(1, $this->push()->sent);
        $this->assertCount(1, $this->sms()->sent);
    }

    public function test_a_push_outage_is_retried_then_marked_failed(): void
    {
        config(['eventapp.campaigns.tries' => 2]);
        $user = $this->person();
        $campaign = $this->campaign([], ['user_ids' => [$user->id]]);
        $this->push()->failNext = 1;

        $this->service()->submit($campaign, $this->author);
        $row = CampaignRecipient::firstOrFail();
        $this->assertSame(RecipientStatus::Queued, $row->status);
        $this->assertSame(1, $row->attempts);

        // The job's retry.
        $this->assertFalse(app(CampaignDelivery::class)->deliver($campaign, Channel::Push, [$row->id]));
        $this->assertSame(RecipientStatus::Sent, $row->fresh()->status);

        $other = CampaignRecipient::create(['campaign_id' => $campaign->id, 'user_id' => $this->person()->id, 'channel' => 'push', 'run_key' => 'x']);
        $this->push()->failNext = 2;
        app(CampaignDelivery::class)->deliver($campaign, Channel::Push, [$other->id]);
        app(CampaignDelivery::class)->deliver($campaign, Channel::Push, [$other->id]);
        $this->assertSame(RecipientStatus::Failed, $other->fresh()->status);
    }

    // ---- SMS webhooks ---------------------------------------------------

    public function test_stop_replies_opt_the_number_out_of_marketing_sms(): void
    {
        $user = $this->person(['phone' => '+233241234567']);

        $this->postJson('/v1/webhooks/sms/inbound', ['from' => '233241234567', 'message' => 'Stop'])->assertForbidden();
        $this->postJson('/v1/webhooks/sms/inbound?token=wrong', ['from' => '233241234567', 'message' => 'STOP'])->assertForbidden();
        $this->postJson('/v1/webhooks/sms/inbound', ['from' => '233241234567', 'message' => 'thanks!'], ['X-Webhook-Token' => 'test-sms-token'])
            ->assertOk()->assertJson(['opted_out' => false]);
        // Rotation: the old and the new secret both work.
        config(['eventapp.campaigns.sms.webhook_token' => 'new-token, test-sms-token']);
        $this->postJson('/v1/webhooks/sms/inbound', ['from' => '233241234567', 'message' => 'ok'], ['X-Webhook-Token' => 'new-token'])->assertOk();
        $this->postJson('/v1/webhooks/sms/inbound?token=test-sms-token', ['from' => '0241234567', 'message' => ' stop. '])
            ->assertOk()->assertJson(['opted_out' => true]);

        $this->assertDatabaseHas('sms_opt_outs', ['phone' => '+233241234567', 'source' => 'stop_reply']);
        $this->service()->submit($this->campaign(['channels' => ['sms']], ['user_ids' => [$user->id]]), $this->author);
        $this->assertSkipped($user, 'sms', SkipReason::SmsStopped);
        $this->assertSame([], $this->sms()->sent);

        // Turning SMS on again in the app lifts it.
        $this->signIn($user);
        $this->getJson('/v1/me/notification-preferences')->assertJson(['sms_stopped' => true]);
        $this->putJson('/v1/me/notification-preferences', ['channels' => ['sms' => ['promotions' => true]]])
            ->assertOk()->assertJson(['sms_stopped' => false]);
    }

    public function test_delivery_reports_mark_sms_delivered_or_failed(): void
    {
        [$a, $b] = [$this->person(), $this->person()];
        $this->service()->submit($this->campaign(['channels' => ['sms']], ['user_ids' => [$a->id, $b->id]]), $this->author);
        [$first, $second] = $this->sms()->sent;

        $this->postJson('/v1/webhooks/sms/delivery?token=test-sms-token', ['message_id' => $first['id'], 'status' => 'DELIVRD'])->assertOk();
        $this->postJson('/v1/webhooks/sms/delivery?token=test-sms-token', ['messageId' => $second['id'], 'status' => 'undeliverable'])->assertOk();
        $this->postJson('/v1/webhooks/sms/delivery?token=test-sms-token', ['message_id' => 'unknown', 'status' => 'DELIVRD'])->assertOk();

        $statuses = CampaignRecipient::pluck('status', 'provider_message_id');
        $this->assertSame(RecipientStatus::Delivered, $statuses[$first['id']]);
        $this->assertSame(RecipientStatus::Failed, $statuses[$second['id']]);
    }

    // ---- App API --------------------------------------------------------

    public function test_users_manage_their_preferences_per_channel_and_category(): void
    {
        $user = $this->signIn($this->person());

        $this->getJson('/v1/me/notification-preferences')->assertOk()
            ->assertJsonPath('channels.push.promotions', true)
            ->assertJsonPath('channels.sms.suggestions', true)
            ->assertJsonPath('categories.0.key', 'promotions');

        $this->putJson('/v1/me/notification-preferences', ['channels' => ['push' => ['promotions' => false], 'in_app' => ['social' => false]]])
            ->assertOk()
            ->assertJsonPath('channels.push.promotions', false)
            ->assertJsonPath('channels.push.social', true)
            ->assertJsonPath('channels.in_app.social', false);
        $this->putJson('/v1/me/notification-preferences', ['channels' => ['email' => ['promotions' => false]]])->assertStatus(422);
        $this->putJson('/v1/me/notification-preferences', ['channels' => ['push' => ['booking_alerts' => false]]])->assertStatus(422);

        $this->service()->submit($this->campaign(['channels' => ['push', 'in_app']], ['user_ids' => [$user->id]]), $this->author);
        $this->assertSkipped($user, 'push', SkipReason::OptedOut);
        $this->assertStatus($user, 'in_app', RecipientStatus::Delivered);
    }

    public function test_the_app_reports_opens_and_taps(): void
    {
        [$a, $b] = [$this->person(), $this->person()];
        $campaign = $this->service()->submit($this->campaign(['title' => 'Same for all', 'body' => 'Same text.', 'channels' => ['push', 'in_app']], ['user_ids' => [$a->id, $b->id]]), $this->author);
        $inApp = CampaignRecipient::where('user_id', $a->id)->where('channel', 'in_app')->firstOrFail();

        $this->signIn($a);
        $this->postJson("/v1/campaigns/{$inApp->id}/opened")->assertOk();
        $this->postJson("/v1/campaigns/{$inApp->id}/opened", ['clicked' => true])->assertOk();
        // The shared push only carried the campaign id.
        $this->postJson("/v1/campaigns/{$campaign->id}/opened", ['clicked' => true])->assertOk();

        $this->signIn($b);
        $this->postJson("/v1/campaigns/{$inApp->id}/opened")->assertNotFound(); // someone else's

        $this->assertNotNull($inApp->fresh()->opened_at);
        $this->assertNotNull($inApp->fresh()->clicked_at);
        $this->assertNotNull(CampaignRecipient::where('user_id', $a->id)->where('channel', 'push')->value('clicked_at'));
        $this->assertNull(CampaignRecipient::where('user_id', $b->id)->where('channel', 'push')->value('opened_at'));
    }

    // ---- Reporting ------------------------------------------------------

    public function test_the_report_counts_reach_engagement_cost_and_bookings(): void
    {
        config(['eventapp.campaigns.sms.cost_per_segment_minor' => 5]);
        $vendor = Vendor::factory()->ownedBy()->create();
        $buyer = $this->person();
        $later = $this->person();
        $noDevice = $this->person(device: false);
        $campaign = $this->campaign(['channels' => ['push', 'sms'], 'body' => 'Short note.'], ['user_ids' => [$buyer->id, $later->id, $noDevice->id]]);
        $this->booking($buyer, $vendor, now()->subDay()); // before the campaign: doesn't count

        $this->service()->submit($campaign, $this->author);
        $this->postJson('/v1/webhooks/sms/delivery?token=test-sms-token', ['message_id' => $this->sms()->sent[0]['id'], 'status' => 'delivered']);
        CampaignRecipient::where('user_id', $buyer->id)->where('channel', 'push')->update(['opened_at' => now(), 'clicked_at' => now()]);
        $this->travel(2)->days();
        $this->booking($buyer, $vendor);
        $this->travel(10)->days();
        $this->booking($later, $vendor); // outside the 7 days

        $report = app(CampaignReport::class)->for($campaign);

        $this->assertSame(3, $report['audience']);
        $this->assertSame(['total' => 3, 'queued' => 0, 'sent' => 2, 'delivered' => 0, 'failed' => 0, 'skipped' => 1, 'opened' => 1, 'clicked' => 1], $report['channels']['push']);
        $this->assertSame(['total' => 3, 'queued' => 0, 'sent' => 3, 'delivered' => 1, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0], $report['channels']['sms']);
        $this->assertSame(['No device' => 1], $report['skipped']);
        // "Short note.\nReply STOP to opt out." is one segment, 3 people × 5 pesewas.
        $this->assertSame(15, $report['sms_cost_minor']);
        $this->assertSame(1, $report['bookings']);
    }

    // ---- Automations ----------------------------------------------------

    public function test_the_welcome_series_reaches_each_new_user_once_per_step(): void
    {
        Campaign::whereIn('automation', ['welcome_day_0', 'welcome_day_1'])->update(['active' => true]);
        $new = $this->person(['created_at' => now()->subHours(3)]);
        $yesterday = $this->person(['created_at' => now()->subHours(30)]);
        $this->person(['created_at' => now()->subDays(20)]);

        $this->artisan('campaigns:automations')->assertSuccessful();
        $this->artisan('campaigns:automations')->assertSuccessful();

        $day0 = Campaign::where('automation', 'welcome_day_0')->value('id');
        $day1 = Campaign::where('automation', 'welcome_day_1')->value('id');
        $this->assertSame([$new->id], CampaignRecipient::where('campaign_id', $day0)->where('channel', 'push')->pluck('user_id')->all());
        $this->assertSame([$yesterday->id], CampaignRecipient::where('campaign_id', $day1)->pluck('user_id')->all());
        $this->assertCount(2, $this->push()->sent);
        $this->assertSame('Welcome to Petalsinajar, '.strtok($new->display_name, ' ').'!', $this->push()->sent[0]['message']->title);
    }

    public function test_automations_that_are_off_or_in_quiet_hours_send_nothing(): void
    {
        $this->person(['created_at' => now()->subHour()]);

        app(LifecycleAutomations::class)->run();
        Campaign::where('automation', 'welcome_day_0')->update(['active' => true]);
        app(LifecycleAutomations::class)->run(CarbonImmutable::parse('2026-11-23 23:00:00'));

        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_event_countdowns_open_the_hosts_event(): void
    {
        Campaign::where('automation', 'event_countdown')->update(['active' => true]);
        $host = $this->person();
        $event = Event::factory()->create(['host_id' => $host->id, 'title' => 'Garden wedding', 'starts_at' => CarbonImmutable::parse('2026-11-30 14:00'), 'ends_at' => CarbonImmutable::parse('2026-11-30 20:00')]);

        app(LifecycleAutomations::class)->run();

        $push = $this->push()->sent[0]['message'];
        $this->assertSame('Garden wedding is coming up', $push->title);
        $this->assertSame("/events/{$event->id}", $push->route);
        $this->assertSame("event:{$event->id}:7", CampaignRecipient::value('run_key'));
    }

    public function test_the_vendor_summary_goes_to_owners_on_its_day(): void
    {
        Campaign::where('automation', 'vendor_weekly_summary')->update(['active' => true]);
        $owner = $this->person();
        $vendor = Vendor::factory()->ownedBy($owner)->create(['name' => 'Royal Canopies']);
        $this->booking($this->person(), $vendor);

        app(LifecycleAutomations::class)->run(); // Monday

        $this->assertSame('Your week at Royal Canopies', $this->push()->sent[0]['message']->title);
        $this->assertStringStartsWith('1 new booking requests', $this->push()->sent[0]['message']->body);

        app(LifecycleAutomations::class)->run(CarbonImmutable::parse('2026-11-24 10:00'));
        $this->assertCount(1, $this->push()->sent, 'only on the summary day');
    }

    // ---- Test sends -----------------------------------------------------

    public function test_send_test_reaches_only_the_admin_and_records_nothing(): void
    {
        $this->author->forceFill(['phone' => '+233200000099'])->save();
        $campaign = $this->campaign(['channels' => ['push', 'sms', 'in_app']], ['user_ids' => [$this->person()->id]]);

        $results = $this->service()->sendTest($campaign, $this->author);

        $this->assertSame(['push', 'sms', 'in_app'], array_keys($results));
        $this->assertSame([$this->author->id], $this->push()->recipients());
        $this->assertStringStartsWith('[Test] ', $this->push()->sent[0]['message']->title);
        $this->assertSame('+233200000099', $this->sms()->sent[0]['phone']);
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertSame(0, CampaignRecipient::count());
    }

    // ---- Admin panel ----------------------------------------------------

    public function test_campaign_pages_render_for_admins_with_the_permission(): void
    {
        $campaign = $this->campaign(['channels' => ['push', 'sms', 'in_app'], 'deep_link' => ['type' => 'discover']], ['roles' => ['host'], 'city' => 'Accra']);
        $sent = $this->service()->submit($this->campaign([], ['user_ids' => [$this->person()->id]]), $this->author);
        $this->actingAs($this->author);

        foreach ([
            '/admin', '/admin/campaigns', '/admin/campaigns/create', "/admin/campaigns/{$campaign->id}/edit",
            "/admin/campaigns/{$campaign->id}", "/admin/campaigns/{$sent->id}", '/admin/campaign-segments', '/admin/automations',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admins_build_an_audience_write_a_campaign_and_send_it_from_the_panel(): void
    {
        $accra = $this->person(['city' => 'Accra']);
        $this->person(['city' => 'Kumasi']);
        $this->actingAs($this->author);

        Livewire::test(ManageCampaignSegments::class)
            ->callAction('create', data: [
                'name' => 'Accra',
                'filters' => ['city' => 'Accra', 'near' => ['lat' => '5.6', 'lng' => null, 'km' => null], 'has_booked' => null],
            ])
            ->assertHasNoActionErrors();
        $segment = CampaignSegment::where('name', 'Accra')->firstOrFail();
        $this->assertSame(['city' => 'Accra'], $segment->filters, 'empty and half-filled filters are dropped');
        $this->assertSame($this->author->id, $segment->created_by);

        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'Accra launch',
                'segment_id' => $segment->id,
                'channels' => ['push', 'in_app'],
                'category' => 'product_updates',
                'title' => 'We’re live in {city}',
                'body' => 'Find vendors near you, {first_name}.',
                'deep_link' => ['type' => 'discover'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $campaign = Campaign::where('name', 'Accra launch')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->callAction('sendTest')
            ->callAction('sendNow');

        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame([$this->author->id, $accra->id], $this->push()->recipients());
        $this->assertSame('We’re live in Accra', $this->push()->sent[1]['message']->title);
    }

    public function test_super_admins_approve_from_the_report_page(): void
    {
        config(['eventapp.campaigns.approval' => ['audience_over' => 0, 'sms_over' => 0]]);
        $user = $this->person();
        $campaign = $this->service()->submit($this->campaign([], ['user_ids' => [$user->id]]), $this->author);
        $this->assertSame(CampaignStatus::PendingApproval, $campaign->status);

        $this->actingAs($this->author);
        Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])->assertActionHidden('approve');

        $this->actingAs(User::factory()->superAdmin()->create());
        Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])->callAction('approve');

        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame([$user->id], $this->push()->recipients());
    }

    public function test_admins_without_the_permission_cannot_open_campaigns(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/campaigns')->assertForbidden();
        $this->get('/admin/campaign-segments')->assertForbidden();
        $this->get('/admin/automations')->assertForbidden();
        $this->get('/admin')->assertOk();
    }

    // ---- Helpers --------------------------------------------------------

    /** A user who can get every channel unless told otherwise. */
    private function person(array $attributes = [], bool $device = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['phone_verified_at' => now()->subMonth(), ...$attributes])->save();
        if ($device) {
            Device::create(['user_id' => $user->id, 'platform' => 'android', 'push_token' => 'tok-'.$user->id]);
        }

        return $user;
    }

    private function campaign(array $attributes = [], array $filters = []): Campaign
    {
        $segment = CampaignSegment::create(['name' => 'Test audience', 'filters' => $filters, 'created_by' => $this->author->id]);

        return Campaign::create([
            'name' => 'Weekend picks',
            'channels' => ['push'],
            'category' => 'promotions',
            'title' => 'Weekend picks',
            'body' => 'Fresh setups near you.',
            'segment_id' => $segment->id,
            'created_by' => $this->author->id,
            ...$attributes,
        ]);
    }

    private function service(): CampaignService
    {
        return app(CampaignService::class);
    }

    private function push(): FakePushProvider
    {
        return app(PushProvider::class);
    }

    private function sms(): FakeSmsProvider
    {
        return app(SmsProvider::class);
    }

    /** @param list<User> $expected */
    private function assertAudience(array $expected, array $filters): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (User $u) => $u->id, $expected),
            SegmentQuery::users($filters)->pluck('users.id')->all(),
            'filters: '.json_encode($filters),
        );
    }

    private function assertStatus(User $user, string $channel, RecipientStatus $status): void
    {
        $this->assertSame($status, CampaignRecipient::where('user_id', $user->id)->where('channel', $channel)->firstOrFail()->status, "{$channel} for {$user->display_name}");
    }

    private function assertSkipped(User $user, string $channel, SkipReason $reason): void
    {
        $row = CampaignRecipient::where('user_id', $user->id)->where('channel', $channel)->firstOrFail();
        $this->assertSame(RecipientStatus::Skipped, $row->status);
        $this->assertSame($reason, $row->skip_reason);
    }

    private function expectRefusal(callable $action, string $why): void
    {
        try {
            $action();
            $this->fail("Expected a refusal: {$why}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function booking(User $customer, Vendor $vendor, ?\DateTimeInterface $at = null): Booking
    {
        $event = Event::factory()->create(['host_id' => $customer->id, 'starts_at' => now()->addDays(40), 'ends_at' => now()->addDays(40)->addHours(6)]);

        $booking = Booking::create([
            'number' => Booking::nextNumber(),
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'window_start' => now()->addDays(39),
            'window_end' => now()->addDays(41),
            'fulfilment' => ['type' => 'delivery'],
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'deposit_due_minor' => 3000,
            'currency' => 'GHS',
            'commission_percent' => 10,
            'commission_minor' => 1000,
            'policy_snapshot' => [],
            'status' => BookingStatus::PendingVendor,
        ]);
        if ($at) {
            $booking->forceFill(['created_at' => $at])->save();
        }

        return $booking;
    }
}

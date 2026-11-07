<?php

namespace Tests\Feature;

use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use App\Modules\Recommendations\Jobs\GenerateEventRecommendations;
use App\Modules\Recommendations\Models\Recommendation;
use App\Modules\Recommendations\Services\RecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Vendor suggestions: matching, timing and limits, the API and the daily command. */
class RecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 in Accra (UTC+0), well outside quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Accra'));
        $this->host = User::factory()->create();
    }

    /** A wedding near East Legon, created long enough ago that it's past the checklist stage. */
    private function event(int $daysUntil = 30, array $attributes = [], int $createdDaysAgo = 10): Event
    {
        $starts = now()->addDays($daysUntil)->setTime(14, 0);
        $event = Event::factory()->create([
            'host_id' => $this->host->id,
            'type' => 'wedding',
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(6),
            ...$attributes,
        ]);
        $event->forceFill(['created_at' => now()->subDays($createdDaysAgo)])->saveQuietly();

        return $event->refresh();
    }

    /** A verified Accra vendor with one item in a category. */
    private function supplier(string $category = 'sound', array $vendor = [], array $variant = []): Vendor
    {
        $model = Vendor::factory()->ownedBy()->create($vendor);
        $model->forceFill(['verification_status' => $vendor['verification_status'] ?? 'verified', 'status' => $vendor['status'] ?? 'active'])->save();
        $this->variant($model, ['title' => ucfirst($category).' set', 'quantity' => 5, 'unitPriceMinor' => 30000, ...$variant], $category);

        return $model;
    }

    /** @return Collection<int, Recommendation> */
    private function suggest(Event $event): Collection
    {
        return app(RecommendationService::class)->run($event->refresh());
    }

    // ---- Matching ------------------------------------------------------------

    public function test_available_vendors_are_suggested_for_a_missing_category(): void
    {
        $event = $this->event();
        $a = $this->supplier('sound');
        $b = $this->supplier('sound');

        $sent = $this->suggest($event);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $sent->pluck('vendor_id')->all());
        $this->assertTrue($sent->every(fn (Recommendation $r) => $r->category_id === 'sound'
            && $r->reason === RecommendationReason::MissingCategory
            && $r->status === RecommendationStatus::Sent
            && $r->sent_at !== null));
        $this->assertSame(1, $sent->pluck('batch_id')->unique()->count());

        $notification = $this->host->notifications()->sole();
        $this->assertSame('vendor_suggestions', $notification->data['type']);
        $this->assertSame('2 sound vendors are free on 10 Dec', $notification->data['title']);
        $this->assertSame("petalsinajar://events/{$event->id}/suggestions", $notification->data['deep_link']);
        $this->assertCount(2, $notification->data['vendors']);
    }

    public function test_only_vendors_who_can_actually_serve_the_event_are_matched(): void
    {
        $event = $this->event(attributes: ['budget_minor' => 100000]); // GH₵1,000: up to GH₵400 per category
        $good = $this->supplier('sound');

        $this->supplier('sound', ['verification_status' => 'pending']);
        $this->supplier('sound', ['status' => 'suspended']);
        $this->supplier('sound', ['event_types' => ['birthday']]);
        $this->supplier('sound', ['base_lat' => 6.6885, 'base_lng' => -1.6244, 'city' => 'Kumasi']); // ~200 km away
        $this->supplier('sound', variant: ['active' => false]);
        $this->supplier('sound', variant: ['unitPriceMinor' => 50000]);                             // over budget
        $this->supplier('lighting');                                                              // other category

        $soldOut = $this->supplier('sound');
        $variant = collect($this->catalog()->activeVariantsInCategory('sound', [$soldOut->id]))->sole();
        $this->store()->reserveMany([new ReservationRequest(
            $soldOut->id, $variant->itemId, $variant->variantId, 5, 5,
            $event->starts_at->subHours(12), $event->ends_at->addHours(12), ReservationKind::Block, reason: 'another job',
        )]);

        $this->assertSame([$good->id], array_map(fn ($m) => $m->vendor->id, app(RecommendationService::class)->matches($event, 'sound', CarbonImmutable::now())));
    }

    public function test_better_and_less_recommended_vendors_rank_first(): void
    {
        config(['eventapp.recommendations.fairness_cap' => 2]);
        $event = $this->event();
        $seasoned = $this->supplier('sound', ['rating_avg' => 4.9, 'rating_count' => 40, 'completed_bookings_count' => 60]);
        $busy = $this->supplier('sound');
        $fresh = $this->supplier('sound');

        // $busy was already suggested to two other hosts this month.
        foreach (range(1, 2) as $i) {
            Recommendation::create(['event_id' => Event::factory()->create()->id, 'vendor_id' => $busy->id,
                'category_id' => 'sound', 'reason' => 'missing_category', 'status' => 'sent']);
        }

        $ranked = array_map(fn ($m) => $m->vendor->id, app(RecommendationService::class)->matches($event, 'sound', CarbonImmutable::now()));

        $this->assertSame([$seasoned->id, $fresh->id, $busy->id], $ranked);
    }

    public function test_booked_categories_and_booked_vendors_are_left_out(): void
    {
        $event = $this->event();
        $chairs = $this->supplier('chairs');
        $sound = $this->supplier('sound');
        $item = collect($this->catalog()->activeVariantsInCategory('chairs', [$chairs->id]))->sole();

        $this->signIn($this->host);
        $this->postJson('/v1/checkouts', ['event_id' => $event->id, 'lines' => [['item_id' => $item->itemId, 'variant_id' => $item->variantId, 'qty' => 2]]])
            ->assertCreated();

        $service = app(RecommendationService::class);
        $this->assertSame(['tables', 'tents', 'decor', 'lighting', 'sound'], $service->missingCategories($event));
        $this->assertSame([$sound->id], $this->suggest($event)->pluck('vendor_id')->all());
    }

    // ---- Timing and limits ---------------------------------------------------

    public function test_at_most_three_vendors_per_notification_two_a_week_and_never_the_same_vendor_twice(): void
    {
        $event = $this->event();
        collect(range(1, 5))->each(fn () => $this->supplier('sound'));

        $first = $this->suggest($event);
        $second = $this->suggest($event);
        $this->assertCount(3, $first);
        $this->assertCount(2, $second);
        $this->assertEmpty($first->pluck('vendor_id')->intersect($second->pluck('vendor_id')));

        // New vendors appear, but this event has had its two notifications this week.
        collect(range(1, 3))->each(fn () => $this->supplier('sound'));
        $this->assertCount(0, $this->suggest($event));

        // A week later it can send again; sound has room for one more (6 per category).
        $this->travel(8)->days();
        $third = $this->suggest($event);
        $this->assertCount(1, $third);
        $this->assertSame(6, Recommendation::where('event_id', $event->id)->distinct()->count('vendor_id'));
        $this->assertSame(3, $this->host->notifications()->count());
    }

    public function test_least_suggested_category_goes_first(): void
    {
        $event = $this->event();
        $this->supplier('sound');
        $this->supplier('tents');

        $this->assertSame('tents', $this->suggest($event)->first()->category_id); // tents comes before sound in config
        $this->assertSame('sound', $this->suggest($event)->first()->category_id);
    }

    public function test_nothing_is_sent_during_quiet_hours(): void
    {
        $event = $this->event();
        $this->supplier('sound');

        $this->travelTo(CarbonImmutable::parse('2026-11-10 21:30:00', 'Africa/Accra'));
        $this->assertCount(0, $this->suggest($event));

        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'Africa/Accra'));
        $this->assertCount(1, $this->suggest($event));
    }

    public function test_each_stage_has_its_own_reason_and_nothing_goes_out_after_the_window(): void
    {
        $this->supplier('sound');

        $this->assertSame(RecommendationReason::Checklist, $this->suggest($this->event(90, createdDaysAgo: 0))->first()->reason);
        $this->assertSame(RecommendationReason::LastCall, $this->suggest($this->event(10))->first()->reason);
        $this->assertCount(0, $this->suggest($this->event(5)));
        $this->assertCount(0, $this->suggest($this->event(90)));
        $this->assertCount(0, $this->suggest($this->event(30, ['status' => 'cancelled'])));
        $this->assertCount(0, $this->suggest($this->event(30, ['type' => 'other'])));
    }

    // ---- Host controls ---------------------------------------------------------

    public function test_hosts_can_turn_suggestions_off(): void
    {
        $event = $this->event();
        $this->supplier('sound');
        $this->host->forceFill(['settings' => ['notify' => ['push' => true]]])->save();

        $this->signIn($this->host);
        $this->patchJson('/v1/me', ['settings' => ['notify' => ['suggestions' => false]]])->assertOk();

        $this->assertSame(['notify' => ['push' => true, 'suggestions' => false]], $this->host->refresh()->settings);
        $this->assertCount(0, $this->suggest($event));
    }

    public function test_dismissed_vendors_disappear_and_repeated_dismissals_stop_the_category(): void
    {
        $event = $this->event();
        collect(range(1, 5))->each(fn () => $this->supplier('sound'));
        $sent = $this->suggest($event);

        $this->signIn($this->host);
        foreach ($sent->take(2) as $recommendation) {
            $this->postJson("/v1/suggestions/{$recommendation->id}/dismiss")->assertOk()->assertJsonPath('status', 'dismissed');
        }

        $this->getJson("/v1/events/{$event->id}/suggestions")->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(0, $this->suggest($event)); // two vendors left, but the host isn't interested in sound
    }

    // ---- Attribution and API ------------------------------------------------

    public function test_booking_a_suggested_vendor_marks_the_suggestion_booked(): void
    {
        $event = $this->event();
        $vendor = $this->supplier('sound');
        $recommendation = $this->suggest($event)->sole();
        $item = collect($this->catalog()->activeVariantsInCategory('sound', [$vendor->id]))->sole();

        $this->signIn($this->host);
        $bookingId = $this->postJson('/v1/checkouts', ['event_id' => $event->id, 'lines' => [['item_id' => $item->itemId, 'variant_id' => $item->variantId, 'qty' => 1]]])
            ->assertCreated()->json('bookings.0.id');

        $recommendation->refresh();
        $this->assertSame(RecommendationStatus::Booked, $recommendation->status);
        $this->assertSame($bookingId, $recommendation->booked_booking_id);

        // Booked stays booked: dismissing afterwards doesn't undo it.
        $this->postJson("/v1/suggestions/{$recommendation->id}/dismiss")->assertOk()->assertJsonPath('status', 'booked');
    }

    public function test_hosts_see_and_open_suggestions_and_vendors_see_their_stats(): void
    {
        $event = $this->event();
        $vendor = $this->supplier('sound', ['rating_avg' => 4.5, 'rating_count' => 8]);
        $recommendation = $this->suggest($event)->sole();

        $this->signIn(User::factory()->create());
        $this->getJson("/v1/events/{$event->id}/suggestions")->assertForbidden();
        $this->postJson("/v1/suggestions/{$recommendation->id}/open")->assertForbidden();
        $this->getJson("/v1/vendors/{$vendor->id}/recommendation-stats")->assertForbidden();

        $this->signIn($this->host);
        $this->getJson("/v1/events/{$event->id}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.0.vendor.id', $vendor->id)
            ->assertJsonPath('data.0.vendor.is_verified', true)
            ->assertJsonPath('data.0.reason', 'missing_category')
            ->assertJsonPath('data.0.from_price_minor', 30000)
            ->assertJsonStructure(['data' => [['id', 'category_id', 'status', 'distance_km', 'sent_at']], 'next_cursor']);

        $this->postJson("/v1/suggestions/{$recommendation->id}/open")->assertOk()->assertJsonPath('status', 'opened');

        $this->signIn($vendor->users()->first());
        $this->getJson("/v1/vendors/{$vendor->id}/recommendation-stats")
            ->assertOk()
            ->assertJson(['recommended' => 1, 'opened' => 1, 'booked' => 0, 'dismissed' => 0]);
    }

    // ---- Running -------------------------------------------------------------

    public function test_the_daily_command_only_queues_one_job_per_due_event(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00:00', 'Africa/Accra'));
        $this->supplier('sound');

        $due = $this->event(30);
        $justCreated = $this->event(120, createdDaysAgo: 0);
        $this->event(120);                              // too far out
        $this->event(-2);                               // already happened
        $this->event(30, ['status' => 'cancelled']);
        $this->event(30, ['type' => 'other']);
        $optedOut = User::factory()->create(['settings' => ['notify' => ['suggestions' => false]]]);
        $this->event(30, ['host_id' => $optedOut->id]);

        $this->artisan('recommendations:run')->assertSuccessful();

        Queue::assertPushed(GenerateEventRecommendations::class, 2);
        Queue::assertPushedOn('recommendations', GenerateEventRecommendations::class);
        Queue::assertPushed(GenerateEventRecommendations::class, function (GenerateEventRecommendations $job) use ($due, $justCreated) {
            // Queued at 03:00, so held until quiet hours end at 08:00.
            return in_array($job->eventId, [$due->id, $justCreated->id], true)
                && CarbonImmutable::instance($job->delay)->setTimezone('Africa/Accra')->format('H:i') === '08:00';
        });
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_job_sends_suggestions_for_its_event(): void
    {
        $event = $this->event();
        $this->supplier('sound');

        GenerateEventRecommendations::dispatch($event->id);

        $this->assertDatabaseHas('recommendations', ['event_id' => $event->id, 'status' => 'sent']);
        $this->assertSame(1, $this->host->notifications()->count());
    }
}

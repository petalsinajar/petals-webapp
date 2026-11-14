<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Catalog\Models\Blackout;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Package;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Subscriptions\Models\FeaturedCredit;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Modules\Subscriptions\Notifications\SubscriptionNotice;
use App\Modules\Subscriptions\Services\SubscriptionMetrics;
use App\Modules\Subscriptions\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Vendor plans: limits, trial, checkout and proration, renewals by card and mobile money,
 * grace and downgrade, commission snapshots, visibility and sponsored slots, analytics.
 */
class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // A Monday morning in Accra, so periods and featured weeks are easy to reason about.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'UTC'));

        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->vendor = Vendor::factory()->ownedBy($this->owner)->create(['verification_status' => 'verified']);
        $this->vendor->members()->create(['user_id' => $this->manager->id, 'role' => VendorRole::Manager, 'status' => 'active']);
    }

    private function url(string $path = ''): string
    {
        return "/v1/vendors/{$this->vendor->id}/subscription{$path}";
    }

    /** Owner starts paying for $plan; returns the checkout response. */
    private function checkout(string $plan, string $interval = 'month'): array
    {
        $this->signIn($this->owner);

        return $this->postJson($this->url('/checkout'), ['plan' => $plan, 'interval' => $interval])->assertOk()->json();
    }

    /** The provider's webhook for a payment (fake gateway). */
    private function webhook(string $paymentId, string $method = 'mobile_money', ?array $authorization = null, string $status = 'success'): void
    {
        $payment = Payment::findOrFail($paymentId);
        $this->postJson('/v1/webhooks/payments', [
            'reference' => $payment->provider_ref,
            'status' => $status,
            'amount_minor' => $payment->amount_minor,
            'method' => $method,
            'authorization' => $authorization,
        ])->assertOk();
    }

    private function subscribe(string $plan, string $method = 'mobile_money', string $interval = 'month'): VendorSubscription
    {
        $checkout = $this->checkout($plan, $interval);
        $this->webhook($checkout['payment_id'], $method, $method === 'card' ? ['code' => 'AUTH_ok', 'reusable' => true, 'last4' => '4081'] : null);

        return VendorSubscription::current()->where('vendor_id', $this->vendor->id)->firstOrFail();
    }

    private function sweep(): void
    {
        Artisan::call('subscriptions:sweep');
    }

    // ---- Plans and entitlements -------------------------------------------

    public function test_plans_are_listed_and_a_vendor_without_a_subscription_is_on_starter(): void
    {
        $this->signIn($this->manager);
        $plans = $this->getJson('/v1/plans')->assertOk()->json('data');
        $this->assertSame(['starter', 'growth', 'pro', 'business'], array_column($plans, 'key'));
        $this->assertSame([0, 9900, 24900, 59900], array_column($plans, 'price_monthly_minor'));
        $this->assertSame([0, 99000, 249000, 599000], array_column($plans, 'price_yearly_minor'));
        $this->assertSame(['items' => null, 'packages' => null, 'team' => 10], $plans[2]['limits']);
        $this->assertTrue($plans[3]['features']['account_manager']);

        // Managers see the plan and usage; only owners change it.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('plan.key', 'starter')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('usage.team', ['used' => 2, 'limit' => 1, 'over_limit' => true])
            ->assertJsonPath('trial_available', true);
        $this->postJson($this->url('/checkout'), ['plan' => 'growth', 'interval' => 'month'])->assertForbidden();
        $this->postJson($this->url('/trial'))->assertForbidden();
        $this->postJson($this->url('/cancel'))->assertForbidden();

        $this->signIn(User::factory()->create());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_limits_stop_new_items_packages_and_members_with_an_upgrade_prompt(): void
    {
        Item::factory()->for($this->vendor)->count(10)->create(['status' => 'active']);
        Item::factory()->for($this->vendor)->count(3)->create(['status' => 'archived']); // don't count
        Package::create(['vendor_id' => $this->vendor->id, 'title' => 'Wedding', 'price_minor' => 1000, 'currency' => 'GHS']);
        $newcomer = User::factory()->create(['phone' => '+233209999999']);

        $this->signIn($this->owner);
        $this->postJson("/v1/vendors/{$this->vendor->id}/items", [])->assertForbidden()
            ->assertExactJson([
                'message' => 'Your plan allows 10 items. Upgrade to add more.',
                'code' => 'plan_limit', 'resource' => 'items', 'limit' => 10, 'used' => 10, 'upgrade_to' => 'growth',
            ]);
        $this->postJson("/v1/vendors/{$this->vendor->id}/packages", [])->assertForbidden()
            ->assertJsonPath('code', 'plan_limit')->assertJsonPath('resource', 'packages')->assertJsonPath('upgrade_to', 'growth');
        $this->postJson("/v1/vendors/{$this->vendor->id}/members", ['phone' => '0209999999', 'role' => 'staff'])->assertForbidden()
            ->assertJsonPath('resource', 'team')->assertJsonPath('limit', 1);
        // Changing an existing member's role isn't a new seat.
        $this->postJson("/v1/vendors/{$this->vendor->id}/members", ['phone' => $this->manager->phone, 'role' => 'staff'])->assertCreated();

        // Growth: room again. Pro and up have no item limit.
        $this->subscribe('growth');
        $this->postJson("/v1/vendors/{$this->vendor->id}/members", ['phone' => '0209999999', 'role' => 'staff'])->assertCreated();
        $this->assertFalse($newcomer->roleIn($this->vendor) === null);
        $this->getJson($this->url())->assertJsonPath('usage.items', ['used' => 10, 'limit' => 50, 'over_limit' => false]);
    }

    // ---- Trial -------------------------------------------------------------

    public function test_one_pro_trial_per_vendor_without_payment(): void
    {
        $this->signIn($this->owner);
        $this->postJson($this->url('/trial'))->assertCreated()
            ->assertJsonPath('plan.key', 'pro')
            ->assertJsonPath('status', 'trialing')
            ->assertJsonPath('is_trial', true)
            ->assertJsonPath('current_period.end', now()->addDays(30)->toIso8601String())
            ->assertJsonPath('trial_available', false);
        $this->assertSame(0, Payment::count());
        $this->postJson($this->url('/trial'))->assertJsonValidationErrors('trial');

        // Reminded before it ends, then back on Starter; no second trial.
        $this->travel(28)->days();
        $this->sweep();
        Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'trial_ending');

        $this->travel(3)->days();
        $this->sweep();
        $this->getJson($this->url())->assertJsonPath('plan.key', 'starter')->assertJsonPath('trial_available', false);
        $this->postJson($this->url('/trial'))->assertJsonValidationErrors('trial');
        $this->assertSame('trial_ended', VendorSubscription::first()->end_reason);
    }

    // ---- Paying --------------------------------------------------------------

    public function test_mobile_money_payment_activates_once_however_often_the_webhook_comes(): void
    {
        $checkout = $this->checkout('growth');
        $this->assertSame(['pay', 'new', 9900, 0], [$checkout['action'], $checkout['kind'], $checkout['amount_minor'], $checkout['credit_minor']]);
        $this->assertNotNull($checkout['authorization_url']);
        $this->assertSame('starter', $checkout['subscription']['plan']['key'], 'nothing changes until it is paid');

        $this->webhook($checkout['payment_id']);
        $this->webhook($checkout['payment_id']);

        $this->assertSame(1, VendorSubscription::count());
        $this->assertSame(1, LedgerEntry::where('type', 'subscription')->count());
        $this->assertSame(9900, (int) LedgerEntry::where('type', 'subscription')->value('amount_minor'));
        $this->getJson($this->url())->assertJsonPath('plan.key', 'growth')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('payment_method', 'mobile_money')
            ->assertJsonPath('current_period.end', now()->addMonth()->toIso8601String());
        $this->assertSame(['growth', 0.03], [$this->vendor->fresh()->plan_key, $this->vendor->fresh()->visibility_boost]);
        Notification::assertSentToTimes($this->owner, SubscriptionNotice::class, 1);
    }

    public function test_a_failed_payment_changes_nothing(): void
    {
        $checkout = $this->checkout('pro');
        $this->webhook($checkout['payment_id'], status: 'failed');

        $this->getJson($this->url())->assertJsonPath('plan.key', 'starter');
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_upgrades_are_immediate_and_charge_the_new_price_minus_the_unused_days(): void
    {
        $growth = $this->subscribe('growth'); // 16 Nov → 16 Dec: 30 days
        $this->travel(10)->days();

        $checkout = $this->checkout('pro');
        // 20 of 30 days unused: 9900 × 20/30 = 6600 credit.
        $this->assertSame(['upgrade', 6600, 24900 - 6600], [$checkout['kind'], $checkout['credit_minor'], $checkout['amount_minor']]);

        $this->webhook($checkout['payment_id']);

        $current = VendorSubscription::current()->where('vendor_id', $this->vendor->id)->first();
        $this->assertSame('pro', $current->plan->key);
        $this->assertTrue($current->current_period_start->equalTo(now()));
        $this->assertSame(['cancelled', 'upgraded', false], [$growth->fresh()->status->value, $growth->fresh()->end_reason, $growth->fresh()->is_current]);
        $this->assertSame(1, FeaturedCredit::where('vendor_id', $this->vendor->id)->count(), 'Pro: one featured week a month');

        // Monthly → yearly on the same plan is an upgrade too.
        $this->travel(15)->days();
        $yearly = $this->checkout('pro', 'year');
        $this->assertSame('upgrade', $yearly['kind']);
        $this->assertSame(249000 - (int) round(24900 * 15 / 30), $yearly['amount_minor']);
    }

    public function test_downgrades_and_cancellations_wait_for_the_period_end(): void
    {
        $this->subscribe('pro', 'card');

        $scheduled = $this->checkout('growth');
        $this->assertSame('scheduled', $scheduled['action']);
        $this->getJson($this->url())->assertJsonPath('plan.key', 'pro')
            ->assertJsonPath('scheduled_change.plan', 'growth');

        // Shortly before the end the card is charged the Growth price; Growth starts at the end.
        $this->travelTo(now()->addMonth()->subHours(2));
        $this->sweep();
        $this->sweep();
        $this->assertSame(2, LedgerEntry::where('type', 'subscription')->count());
        $this->assertSame(9900, (int) LedgerEntry::where('type', 'subscription')->latest('created_at')->value('amount_minor'));
        $current = VendorSubscription::current()->where('vendor_id', $this->vendor->id)->first();
        $this->assertSame(['growth', 'card'], [$current->plan->key, $current->payment_method]);
        $this->assertTrue($current->current_period_start->equalTo(CarbonImmutable::parse('2026-12-16 09:00', 'UTC')));

        // Cancelling keeps Growth until its end, can be undone, and then ends on Starter.
        $this->postJson($this->url('/cancel'))->assertOk()->assertJsonPath('cancel_at_period_end', true);
        $this->postJson($this->url('/resume'))->assertOk()->assertJsonPath('cancel_at_period_end', false);
        $this->postJson($this->url('/cancel'))->assertOk();

        $this->travelTo($current->current_period_end->subHour());
        $this->sweep();
        $this->assertSame(2, LedgerEntry::where('type', 'subscription')->count(), 'a cancelled plan is not charged');
        $this->travel(2)->hours();
        $this->sweep();
        $this->getJson($this->url())->assertJsonPath('plan.key', 'starter');
        Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'downgraded');
    }

    public function test_cards_renew_automatically(): void
    {
        $subscription = $this->subscribe('growth', 'card');
        $this->assertSame('4081', $subscription->card_last4);

        $this->travelTo($subscription->current_period_end->subHours(3));
        $this->sweep();

        $subscription->refresh();
        $this->assertTrue($subscription->current_period_end->equalTo(CarbonImmutable::parse('2027-01-16 09:00', 'UTC')));
        $this->assertSame(2, LedgerEntry::where('type', 'subscription')->count());
        Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'renewed');
        Notification::assertNotSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'renewal_due');

        // Auto-renew off: it isn't charged; the owner is reminded instead.
        $this->signIn($this->owner);
        $this->postJson($this->url('/auto-renew'), ['on' => false])->assertOk()->assertJsonPath('auto_renew', false);
        $this->travelTo($subscription->current_period_end->subDays(2));
        $this->sweep();
        $this->assertSame(2, LedgerEntry::where('type', 'subscription')->count());
        Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'renewal_due');
    }

    public function test_a_declined_card_goes_to_grace_at_the_period_end(): void
    {
        $checkout = $this->checkout('growth');
        $this->webhook($checkout['payment_id'], 'card', ['code' => 'AUTH_declined_1', 'reusable' => true]);
        $subscription = VendorSubscription::current()->firstOrFail();

        $this->travelTo($subscription->current_period_end->subHours(2));
        $this->sweep();
        Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'card_declined');

        $this->travel(3)->hours();
        $this->sweep();
        $this->assertSame('grace', $subscription->fresh()->status->value);
    }

    public function test_mobile_money_reminders_then_grace_then_starter_without_deleting_anything(): void
    {
        $subscription = $this->subscribe('growth');
        $items = Item::factory()->for($this->vendor)->withVariant()->count(30)->create(['status' => 'active']);
        Package::create(['vendor_id' => $this->vendor->id, 'title' => 'A', 'price_minor' => 1000, 'currency' => 'GHS']);
        Package::create(['vendor_id' => $this->vendor->id, 'title' => 'B', 'price_minor' => 1000, 'currency' => 'GHS']);

        $end = $subscription->current_period_end;
        foreach ([3, 1] as $days) {
            $this->travelTo($end->subDays($days)->addMinute());
            $this->sweep();
            $this->sweep();
        }
        $reminders = Notification::sent($this->owner, SubscriptionNotice::class)->filter(fn ($n) => $n->kind === 'renewal_due');
        $this->assertSame([3, 1], $reminders->map(fn ($n) => $n->extra['days_left'])->values()->all());
        $this->assertSame("petalsinajar://vendors/{$this->vendor->id}/subscription", $reminders->first()->toArray($this->owner)['pay_link']);

        // Unpaid at the end: grace, benefits continue, the app shows a banner.
        $this->travelTo($end->addMinute());
        $this->sweep();
        $this->signIn($this->owner);
        $this->getJson($this->url())->assertJsonPath('status', 'grace')
            ->assertJsonPath('plan.key', 'growth')
            ->assertJsonPath('banner.kind', 'grace')
            ->assertJsonPath('grace_ends_at', $end->addDays(3)->toIso8601String());

        // Grace over: Starter. Everything stays live and bookable; only adding is blocked.
        $this->travelTo($end->addDays(3)->addMinute());
        $this->sweep();
        $this->getJson($this->url())->assertJsonPath('plan.key', 'starter')
            ->assertJsonPath('usage.items', ['used' => 30, 'limit' => 10, 'over_limit' => true]);
        $this->assertSame(30, Item::where('vendor_id', $this->vendor->id)->where('status', 'active')->count());
        $this->assertSame(2, Package::where('vendor_id', $this->vendor->id)->where('status', 'active')->count());
        $this->assertSame(2, $this->vendor->members()->where('status', 'active')->count());
        $this->getJson("/v1/items/{$items->first()->id}")->assertOk();
        $this->postJson("/v1/vendors/{$this->vendor->id}/items", [])->assertForbidden()->assertJsonPath('upgrade_to', 'growth'); // 50 items fit 30
        $this->assertSame('expired', $subscription->fresh()->status->value);

        // Paying again brings the plan back, from now.
        $again = $this->checkout('growth');
        $this->assertSame('new', $again['kind']);
    }

    // ---- Commission ------------------------------------------------------

    public function test_each_booking_keeps_the_commission_of_the_vendors_plan_at_checkout(): void
    {
        config(['eventapp.payments.in_app' => false]);
        $host = User::factory()->create();
        $event = Event::factory()->create(['host_id' => $host->id, 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(6)]);
        $chairs = $this->variant($this->vendor, ['quantity' => 500, 'unitPriceMinor' => 1000]);
        $book = function () use ($host, $event, $chairs) {
            $this->signIn($host);

            return Booking::findOrFail($this->postJson('/v1/checkouts', [
                'event_id' => $event->id,
                'lines' => [['item_id' => $chairs->itemId, 'variant_id' => $chairs->variantId, 'qty' => 100]],
            ])->assertCreated()->json('bookings.0.id'));
        };

        $starter = $book();
        $this->subscribe('pro');
        $pro = $book();

        $this->assertSame([12.0, 12000], [$starter->commission_percent, $starter->commission_minor]);
        $this->assertSame([8.0, 8000], [$pro->commission_percent, $pro->commission_minor]);

        Plan::byKey('pro')->update(['commission_percent' => 7]);
        $this->assertSame(8.0, $pro->fresh()->commission_percent, 'later plan changes never touch a booking');
    }

    // ---- Visibility --------------------------------------------------------

    public function test_a_plan_boost_nudges_between_similar_distances_but_not_past_a_clearly_nearer_vendor(): void
    {
        $at = [5.6037, -0.1870];
        $near = Vendor::factory()->create(['name' => 'Near', 'base_lat' => 5.6037 + 0.09, 'base_lng' => -0.1870]); // ~10 km
        $alsoNear = Vendor::factory()->onPlan('business')->create(['name' => 'Boosted', 'base_lat' => 5.6037 + 0.095, 'base_lng' => -0.1870]); // ~10.6 km
        $far = Vendor::factory()->onPlan('business')->create(['name' => 'Far', 'base_lat' => 5.6037 + 0.18, 'base_lng' => -0.1870]); // ~20 km
        $this->vendor->update(['base_lat' => 5.6037 + 0.27]); // ~30 km

        $this->signIn();
        $names = array_column($this->getJson("/v1/vendors?lat={$at[0]}&lng={$at[1]}")->assertOk()->json('data'), 'name');

        $this->assertSame(['Boosted', 'Near', 'Far', $this->vendor->name], $names);
        $this->assertSame([0.1, 0.0], [$alsoNear->fresh()->visibility_boost, $near->fresh()->visibility_boost]);
        $this->assertNotNull($far);
    }

    public function test_sponsored_slots_only_show_featured_vendors_that_match_and_are_labelled(): void
    {
        $this->subscribe('pro');
        $credit = FeaturedCredit::where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendor->update(['event_types' => ['wedding']]);
        $search = fn (string $query = '') => $this->getJson('/v1/vendors?lat=5.6037&lng=-0.1870'.$query)->assertOk();

        $this->signIn($this->owner);
        $this->postJson("/v1/vendors/{$this->vendor->id}/featured-credits/{$credit->id}/use", ['week_start' => '2026-11-18'])
            ->assertJsonValidationErrors('week_start'); // a Wednesday
        $this->postJson("/v1/vendors/{$this->vendor->id}/featured-credits/{$credit->id}/use", ['week_start' => '2026-11-23'])
            ->assertOk()->assertJsonPath('used', true);
        $this->postJson("/v1/vendors/{$this->vendor->id}/featured-credits/{$credit->id}/use", ['week_start' => '2026-11-30'])
            ->assertJsonValidationErrors('week_start'); // already used

        // Not running yet (next week).
        $search()->assertJsonPath('sponsored', []);

        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00', 'UTC'));
        $search('&event_type=wedding')
            ->assertJsonPath('sponsored.0.id', $this->vendor->id)
            ->assertJsonPath('sponsored.0.sponsored', true)
            ->assertJsonPath('sponsored.0.label', 'Sponsored');
        $search('&event_type=birthday')->assertJsonPath('sponsored', []);           // wrong event type
        $this->getJson('/v1/vendors?lat=6.6885&lng=-1.6244')->assertJsonPath('sponsored', []); // Kumasi: outside the area
        Blackout::create(['vendor_id' => $this->vendor->id, 'starts_on' => '2026-12-05', 'ends_on' => '2026-12-06', 'created_by' => $this->owner->id]);
        $search('&date=2026-12-05')->assertJsonPath('sponsored', []);               // closed that day
        $search('&date=2026-12-12')->assertJsonPath('sponsored.0.id', $this->vendor->id);

        // The organic list is unchanged by sponsorship.
        $this->assertSame([$this->vendor->id], array_column($search()->json('data'), 'id'));

        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00', 'UTC'));
        $search()->assertJsonPath('sponsored', []); // the week is over
    }

    public function test_featured_weeks_are_granted_each_paid_month_and_not_for_trials(): void
    {
        $this->signIn($this->owner);
        $this->postJson($this->url('/trial'))->assertCreated();
        $this->sweep();
        $this->assertSame(0, FeaturedCredit::count());

        $this->subscribe('business', 'card', 'year');
        $this->assertSame(2, FeaturedCredit::where('granted_for_period', '2026-11')->count());
        $this->sweep();
        $this->assertSame(2, FeaturedCredit::count());

        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00', 'UTC'));
        $this->sweep();
        $this->assertSame(2, FeaturedCredit::where('granted_for_period', '2026-12')->count());
        $this->getJson($this->url())->assertJsonCount(4, 'featured_credits');
    }

    // ---- Analytics -------------------------------------------------------

    public function test_analytics_only_returns_what_the_plan_includes(): void
    {
        $this->signIn($this->manager);
        $basic = $this->getJson("/v1/vendors/{$this->vendor->id}/analytics")->assertOk()->json();
        $this->assertSame('basic', $basic['level']);
        $this->assertSame(['bookings', 'completed_bookings', 'rating'], array_keys($basic['metrics']));
        $this->assertContains('revenue_minor', $basic['locked']);

        $this->subscribe('pro');
        $this->signIn($this->manager);
        $full = $this->getJson("/v1/vendors/{$this->vendor->id}/analytics?days=90")->assertOk()->json();
        $this->assertSame('full', $full['level']);
        $this->assertArrayHasKey('shop_taps', $full['metrics']);
        $this->assertArrayNotHasKey('daily', $full['metrics']);
        $this->assertSame(['daily', 'repeat_customers'], $full['locked']);

        $staff = User::factory()->create();
        $this->vendor->members()->create(['user_id' => $staff->id, 'role' => VendorRole::Staff, 'status' => 'active']);
        $this->signIn($staff);
        $this->getJson("/v1/vendors/{$this->vendor->id}/analytics")->assertForbidden();
    }

    // ---- Platform team ---------------------------------------------------

    public function test_admins_comp_and_extend_plans_and_see_revenue(): void
    {
        $this->subscribe('growth');
        Vendor::factory()->onPlan('pro')->create(); // a comp: not revenue
        $yearly = Vendor::factory()->ownedBy()->create();
        app(SubscriptionService::class)->comp($yearly, Plan::byKey('business'), 30, 'Launch partner', null);

        $metrics = app(SubscriptionMetrics::class);
        $this->assertSame(9900, $metrics->mrrMinor());
        $this->assertSame(['starter' => 0, 'growth' => 1, 'pro' => 1, 'business' => 1], $metrics->activeByPlan());

        $current = VendorSubscription::current()->where('vendor_id', $this->vendor->id)->first();
        $end = $current->current_period_end;
        app(SubscriptionService::class)->extend($current, 14, 'Outage compensation', User::factory()->admin()->create());
        $this->assertTrue($current->fresh()->current_period_end->equalTo($end->addDays(14)));
        $this->assertDatabaseHas('subscription_adjustments', ['subscription_id' => $current->id, 'kind' => 'extend', 'days' => 14, 'reason' => 'Outage compensation']);

        $this->signIn($this->owner);
        $this->postJson($this->url('/cancel'))->assertOk();
        $this->travelTo($current->fresh()->current_period_end->addMinute());
        $this->sweep();
        $this->assertSame(['churned' => 1, 'rate' => 1.0], $metrics->churnThisMonth());
    }
}

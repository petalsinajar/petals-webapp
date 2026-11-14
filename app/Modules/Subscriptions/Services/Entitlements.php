<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Exceptions\FeatureNotInPlan;
use App\Modules\Subscriptions\Exceptions\PlanLimitReached;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\VendorSubscription;
use Illuminate\Support\Facades\DB;

/**
 * What a vendor's plan allows. The plan is the current subscription's while it gives
 * benefits (trialing, active, grace), otherwise Starter. Limits only stop adding more:
 * listings over a limit stay live, and verification, reviews, ratings and deposit
 * protection never depend on the plan.
 *
 * Scoped per request/job; SubscriptionService forgets a vendor when its plan changes.
 */
class Entitlements
{
    /** @var array<string, array{plan: Plan, subscription: ?VendorSubscription}> */
    private array $resolved = [];

    public function subscription(Vendor|string $vendor): ?VendorSubscription
    {
        return $this->resolve($vendor)['subscription'];
    }

    public function plan(Vendor|string $vendor): Plan
    {
        return $this->resolve($vendor)['plan'];
    }

    public function forget(Vendor|string $vendor): void
    {
        unset($this->resolved[$this->id($vendor)]);
    }

    /** @return array{items: ?int, packages: ?int, team: ?int} null = unlimited */
    public function limits(Vendor|string $vendor): array
    {
        $plan = $this->plan($vendor);

        return ['items' => $plan->limit('items'), 'packages' => $plan->limit('packages'), 'team' => $plan->limit('team')];
    }

    /** @return array{items: int, packages: int, team: int} */
    public function usage(Vendor|string $vendor): array
    {
        $id = $this->id($vendor);

        return [
            'items' => DB::table('items')->where('vendor_id', $id)->where('status', '!=', 'archived')->count(),
            'packages' => DB::table('packages')->where('vendor_id', $id)->where('status', '!=', 'archived')->count(),
            'team' => DB::table('vendor_members')->where('vendor_id', $id)->where('status', 'active')->count(),
        ];
    }

    public function canAddItem(Vendor|string $vendor): bool
    {
        return $this->hasRoom($vendor, 'items');
    }

    public function canAddPackage(Vendor|string $vendor): bool
    {
        return $this->hasRoom($vendor, 'packages');
    }

    public function canAddMember(Vendor|string $vendor): bool
    {
        return $this->hasRoom($vendor, 'team');
    }

    /** @throws PlanLimitReached */
    public function ensureRoomFor(Vendor|string $vendor, string $resource): void
    {
        $limit = $this->plan($vendor)->limit($resource);
        $used = $this->usage($vendor)[$resource];

        if ($limit !== null && $used >= $limit) {
            throw new PlanLimitReached($resource, $limit, $used, $this->upgradeFor($vendor, fn (Plan $p) => $p->limit($resource) === null || $p->limit($resource) > $used));
        }
    }

    public function hasFeature(Vendor|string $vendor, string $key): bool
    {
        return (bool) $this->plan($vendor)->feature($key);
    }

    /** @throws FeatureNotInPlan */
    public function ensureFeature(Vendor|string $vendor, string $key): void
    {
        if (! $this->hasFeature($vendor, $key)) {
            throw new FeatureNotInPlan($key, $this->upgradeFor($vendor, fn (Plan $p) => (bool) $p->feature($key)));
        }
    }

    /** basic | standard | full | full_plus */
    public function analyticsLevel(Vendor|string $vendor): string
    {
        return (string) ($this->plan($vendor)->feature('analytics') ?: 'basic');
    }

    /** Snapshotted onto each booking at checkout. */
    public function commissionPercent(Vendor|string $vendor): float
    {
        return $this->plan($vendor)->commission_percent;
    }

    private function hasRoom(Vendor|string $vendor, string $resource): bool
    {
        $limit = $this->plan($vendor)->limit($resource);

        return $limit === null || $this->usage($vendor)[$resource] < $limit;
    }

    /** The cheapest active plan above the current one that satisfies $allows. */
    private function upgradeFor(Vendor|string $vendor, callable $allows): ?string
    {
        $current = $this->plan($vendor);

        return Plan::where('active', true)->ordered()->get()
            ->first(fn (Plan $p) => $p->sort > $current->sort && $allows($p))?->key;
    }

    /** @return array{plan: Plan, subscription: ?VendorSubscription} */
    private function resolve(Vendor|string $vendor): array
    {
        $id = $this->id($vendor);

        return $this->resolved[$id] ??= (function () use ($id) {
            $subscription = VendorSubscription::current()->where('vendor_id', $id)->with('plan', 'scheduledPlan')->first();
            $benefits = $subscription && $subscription->status->givesBenefits();

            return ['plan' => $benefits ? $subscription->plan : Plan::default(), 'subscription' => $subscription];
        })();
    }

    private function id(Vendor|string $vendor): string
    {
        return $vendor instanceof Vendor ? $vendor->getKey() : $vendor;
    }
}

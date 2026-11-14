<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Enums\BillingInterval;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\VendorSubscription;
use Carbon\CarbonImmutable;

/** Revenue summary for the admin panel. */
class SubscriptionMetrics
{
    /** Paying subscriptions now: active or in grace, not trials, paid at least once (comps don't count). */
    private function paying()
    {
        return VendorSubscription::current()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Grace])
            ->where('is_trial', false)
            ->whereHas('payments', fn ($q) => $q->where('status', 'paid'))
            ->with('plan');
    }

    /** Monthly recurring revenue in minor units: yearly plans count a twelfth. */
    public function mrrMinor(): int
    {
        return (int) $this->paying()->get()->sum(fn (VendorSubscription $s) => $s->interval === BillingInterval::Year
            ? intdiv($s->plan->price_yearly_minor, 12)
            : $s->plan->price_monthly_minor);
    }

    /** @return array<string, int> plan key => vendors on it now (trials and grace included; Starter is everyone else) */
    public function activeByPlan(): array
    {
        $counts = VendorSubscription::current()
            ->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::Grace])
            ->join('plans', 'plans.id', '=', 'vendor_subscriptions.plan_id')
            ->groupBy('plans.key')->selectRaw('plans.key, COUNT(*) AS n')->pluck('n', 'key');

        $starter = Plan::default()->key;
        $result = Plan::ordered()->pluck('key')->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all();
        $result[$starter] = Vendor::where('status', 'active')->count() - array_sum(array_diff_key($result, [$starter => 0]));

        return $result;
    }

    /**
     * Paid (at least once) subscriptions that ended this month (cancelled or lapsed after grace), and that as a
     * share of those plus the ones still paying.
     *
     * @return array{churned: int, rate: float}
     */
    public function churnThisMonth(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $churned = VendorSubscription::where('is_trial', false)
            ->whereIn('end_reason', ['cancelled', 'expired'])
            ->whereHas('payments', fn ($q) => $q->where('status', 'paid')) // comps lapsing aren't churn
            ->where('ended_at', '>=', $now->setTimezone(config('eventapp.business_timezone'))->startOfMonth())
            ->distinct()->count('vendor_id');
        $paying = $this->paying()->count();

        return ['churned' => $churned, 'rate' => $churned + $paying > 0 ? round($churned / ($churned + $paying), 4) : 0.0];
    }
}

<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Enums\BillingInterval;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Http\Resources\PlanResource;
use App\Modules\Subscriptions\Models\FeaturedCredit;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Services\Entitlements;
use App\Modules\Subscriptions\Services\FeaturedPlacements;
use App\Modules\Subscriptions\Services\SubscriptionService;
use App\Modules\Subscriptions\Services\VendorAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Vendor plans. Owners change plans and pay; managers can see the plan and usage.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Entitlements $entitlements,
    ) {}

    /** GET /v1/plans */
    public function plans(): JsonResponse
    {
        return response()->json(['data' => PlanResource::collection(Plan::where('active', true)->ordered()->get())->resolve()]);
    }

    /** GET /v1/vendors/{vendor}/subscription — plan, status, period, usage vs limits, features, featured weeks. */
    public function show(Vendor $vendor): JsonResponse
    {
        Gate::authorize('manage', $vendor);

        return response()->json($this->payload($vendor));
    }

    /** POST /v1/vendors/{vendor}/subscription/trial */
    public function trial(Request $request, Vendor $vendor): JsonResponse
    {
        $this->subscriptions->startTrial($vendor, $request->user());

        return response()->json($this->payload($vendor), 201);
    }

    /**
     * POST /v1/vendors/{vendor}/subscription/checkout { plan, interval }
     * → { action: pay, authorization_url, amount_minor, credit_minor, ... } (upgrade, new or renewal)
     *   or { action: scheduled, starts_at } (downgrade at the period end).
     */
    public function checkout(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'key')],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ]);

        $result = $this->subscriptions->checkout($vendor, $request->user(), Plan::byKey($data['plan']), BillingInterval::from($data['interval']));

        return response()->json([...$result, 'subscription' => $this->payload($vendor)]);
    }

    /** POST /v1/vendors/{vendor}/subscription/cancel — at the period end. */
    public function cancel(Request $request, Vendor $vendor): JsonResponse
    {
        $this->subscriptions->cancel($vendor, $request->user());

        return response()->json($this->payload($vendor));
    }

    /** POST /v1/vendors/{vendor}/subscription/resume */
    public function resume(Request $request, Vendor $vendor): JsonResponse
    {
        $this->subscriptions->resume($vendor, $request->user());

        return response()->json($this->payload($vendor));
    }

    /** POST /v1/vendors/{vendor}/subscription/auto-renew { on: bool } */
    public function autoRenew(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->subscriptions->setAutoRenew($vendor, $request->user(), (bool) $data['on']);

        return response()->json($this->payload($vendor));
    }

    /** POST /v1/vendors/{vendor}/featured-credits/{credit}/use { week_start: Y-m-d (a Monday) } */
    public function useCredit(Request $request, Vendor $vendor, FeaturedCredit $credit, FeaturedPlacements $placements): JsonResponse
    {
        Gate::authorize('own', $vendor);
        abort_unless($credit->vendor_id === $vendor->id, 404);
        $data = $request->validate(['week_start' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->credit($placements->use($credit, $data['week_start'])));
    }

    /** GET /v1/vendors/{vendor}/analytics?days= — only the metrics the plan includes. */
    public function analytics(Request $request, Vendor $vendor, VendorAnalytics $analytics): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $data = $request->validate(['days' => ['sometimes', 'integer', 'between:1,'.config('eventapp.analytics.max_days')]]);

        return response()->json($analytics->report($vendor, (int) ($data['days'] ?? 30)));
    }

    /** @return array<string, mixed> */
    private function payload(Vendor $vendor): array
    {
        $this->entitlements->forget($vendor);
        $subscription = $this->entitlements->subscription($vendor);
        $plan = $this->entitlements->plan($vendor);
        $limits = $this->entitlements->limits($vendor);
        $usage = $this->entitlements->usage($vendor);
        $benefits = $subscription?->status->givesBenefits() ?? false;
        $tz = config('eventapp.business_timezone');

        return [
            'vendor_id' => $vendor->id,
            'plan' => (new PlanResource($plan))->resolve(),
            'status' => $benefits ? $subscription->status->value : 'active', // Starter never lapses
            'is_trial' => $benefits && $subscription->is_trial,
            'interval' => $benefits ? $subscription->interval->value : null,
            'current_period' => $benefits ? [
                'start' => $subscription->current_period_start->toIso8601String(),
                'end' => $subscription->current_period_end->toIso8601String(),
            ] : null,
            'grace_ends_at' => $benefits ? $subscription->grace_ends_at?->toIso8601String() : null,
            'cancel_at_period_end' => $benefits && $subscription->cancel_at_period_end,
            'scheduled_change' => $benefits && $subscription->scheduled_plan_id ? [
                'plan' => $subscription->scheduledPlan->key,
                'interval' => $subscription->renewalInterval()->value,
                'starts_at' => $subscription->current_period_end->toIso8601String(),
            ] : null,
            'auto_renew' => $benefits && $subscription->auto_renew,
            'payment_method' => $benefits ? $subscription->payment_method : null,
            'card_last4' => $benefits ? $subscription->card_last4 : null,
            'trial_available' => $this->subscriptions->trialAvailable($vendor),
            // Grace: the app shows a banner asking the owner to pay.
            'banner' => $benefits && $subscription->status === SubscriptionStatus::Grace ? [
                'kind' => 'grace',
                'message' => "{$plan->name} wasn't renewed. Pay by ".$subscription->grace_ends_at->setTimezone($tz)->format('j M').' to keep its benefits.',
                'ends_at' => $subscription->grace_ends_at->toIso8601String(),
            ] : null,
            'usage' => collect($limits)->map(fn (?int $limit, string $key) => [
                'used' => $usage[$key],
                'limit' => $limit,
                'over_limit' => $limit !== null && $usage[$key] > $limit,
            ])->all(),
            'features' => $plan->features ?? (object) [],
            'featured_credits' => FeaturedCredit::where('vendor_id', $vendor->id)
                ->where(fn ($q) => $q->whereNull('used_at')->where('expires_at', '>', now())
                    ->orWhere('placement_ends_at', '>', now()))
                ->orderBy('expires_at')->get()->map(fn (FeaturedCredit $c) => $this->credit($c))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function credit(FeaturedCredit $credit): array
    {
        return [
            'id' => $credit->id,
            'granted_for_period' => $credit->granted_for_period,
            'expires_at' => $credit->expires_at->toIso8601String(),
            'used' => $credit->used_at !== null,
            'placement' => $credit->used_at ? [
                'starts_at' => $credit->placement_starts_at->toIso8601String(),
                'ends_at' => $credit->placement_ends_at->toIso8601String(),
            ] : null,
        ];
    }
}

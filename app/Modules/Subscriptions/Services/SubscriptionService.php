<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Payments\Data\WebhookResult;
use App\Modules\Payments\Models\LedgerEntry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Subscriptions\Enums\BillingInterval;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\FeaturedCredit;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Modules\Subscriptions\Notifications\SubscriptionNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Vendor plans over time.
 *
 * - Trial: one 30-day Pro trial per vendor, no payment.
 * - Upgrade: immediate once paid; the price is reduced by the unused value of the current
 *   period, prorated by days. Downgrades and cancellations take effect at the period end.
 * - Renewal: cards are charged automatically; mobile money is prepaid, so owners get
 *   reminders with a pay link. Unpaid at the end: grace (benefits continue), then Starter.
 * - A subscription payment only takes effect through its webhook (activate()), once.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly Entitlements $entitlements,
    ) {}

    public function current(Vendor $vendor): ?VendorSubscription
    {
        return VendorSubscription::current()->where('vendor_id', $vendor->id)->with('plan', 'scheduledPlan')->first();
    }

    public function trialAvailable(Vendor $vendor): bool
    {
        return ! VendorSubscription::where('vendor_id', $vendor->id)->where('is_trial', true)->exists()
            && ! $this->current($vendor)?->status->givesBenefits();
    }

    // ---- Owner actions ---------------------------------------------------

    public function startTrial(Vendor $vendor, User $owner): VendorSubscription
    {
        $this->authorizeOwner($owner, $vendor);
        if (! $this->trialAvailable($vendor)) {
            throw ValidationException::withMessages(['trial' => 'The free trial can only be used once, by a vendor on Starter.']);
        }

        $now = CarbonImmutable::now();
        $subscription = DB::transaction(fn () => $this->replaceCurrent($vendor, 'changed', [
            'plan_id' => Plan::byKey(config('eventapp.subscriptions.trial_plan'))->id,
            'interval' => BillingInterval::Month,
            'status' => SubscriptionStatus::Trialing,
            'is_trial' => true,
            'current_period_start' => $now,
            'current_period_end' => $now->addDays(config('eventapp.subscriptions.trial_days')),
        ]));

        $this->notifyOwners($subscription, 'trial_started');

        return $subscription;
    }

    /**
     * What changing to $plan / $interval means now: a new plan, an upgrade (with credit), a
     * renewal of the same plan, or a downgrade scheduled for the period end.
     *
     * @return array{kind: 'new'|'upgrade'|'renewal'|'downgrade', amount_minor: int, credit_minor: int, starts_at: CarbonImmutable}
     */
    public function quote(Vendor $vendor, Plan $plan, BillingInterval $interval, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $current = $this->current($vendor);
        $price = $plan->price($interval);

        if (! $current || ! $current->status->givesBenefits() || $current->is_trial) {
            return ['kind' => 'new', 'amount_minor' => $price, 'credit_minor' => 0, 'starts_at' => $now];
        }

        $samePlan = $current->plan_id === $plan->id;
        if ($samePlan && $current->interval === $interval) {
            return ['kind' => 'renewal', 'amount_minor' => $price, 'credit_minor' => 0, 'starts_at' => $current->current_period_end];
        }

        $isUpgrade = $plan->sort > $current->plan->sort
            || ($samePlan && $interval === BillingInterval::Year);
        if (! $isUpgrade) {
            return ['kind' => 'downgrade', 'amount_minor' => 0, 'credit_minor' => 0, 'starts_at' => $current->current_period_end];
        }

        $credit = min($price, $this->unusedValue($current, $now));

        return ['kind' => 'upgrade', 'amount_minor' => $price - $credit, 'credit_minor' => $credit, 'starts_at' => $now];
    }

    /**
     * Unused value of the current period, prorated by whole days left: what's left of the
     * period's price, rounded to minor units. Nothing is left once the period has ended.
     */
    public function unusedValue(VendorSubscription $subscription, CarbonImmutable $now): int
    {
        $totalDays = max(1, (int) round($subscription->current_period_start->diffInDays($subscription->current_period_end)));
        $daysLeft = max(0, (int) floor($now->diffInDays($subscription->current_period_end, false)));

        return (int) round($subscription->plan->price($subscription->interval) * min($daysLeft, $totalDays) / $totalDays);
    }

    /**
     * Start paying for $plan, or schedule a downgrade.
     *
     * @return array{action: 'pay'|'scheduled'|'activated', kind: string, amount_minor: int, credit_minor: int, currency: string, authorization_url?: ?string, payment_id?: string}
     */
    public function checkout(Vendor $vendor, User $owner, Plan $plan, BillingInterval $interval): array
    {
        $this->authorizeOwner($owner, $vendor);
        if (! $plan->active || $plan->isFree()) {
            throw ValidationException::withMessages(['plan' => $plan->isFree()
                ? 'To move to Starter, cancel the current plan; it ends at the period end.'
                : 'This plan is not available.']);
        }

        $quote = $this->quote($vendor, $plan, $interval);
        $base = ['kind' => $quote['kind'], 'amount_minor' => $quote['amount_minor'], 'credit_minor' => $quote['credit_minor'], 'currency' => $plan->currency];

        if ($quote['kind'] === 'downgrade') {
            $current = $this->current($vendor);
            $current->forceFill(['scheduled_plan_id' => $plan->id, 'scheduled_interval' => $interval, 'cancel_at_period_end' => false])->save();
            $this->notifyOwners($current->refresh(), 'change_scheduled');

            return ['action' => 'scheduled', ...$base, 'starts_at' => $quote['starts_at']->toIso8601String()];
        }

        $current = $this->current($vendor);
        $intent = SubscriptionPayment::create([
            'vendor_id' => $vendor->id,
            'subscription_id' => $quote['kind'] === 'renewal' ? $current->id : null,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'kind' => $quote['kind'],
            'amount_minor' => $quote['amount_minor'],
            'credit_minor' => $quote['credit_minor'],
            'currency' => $plan->currency,
        ]);

        // A credit that covers the whole price: nothing to charge.
        if ($quote['amount_minor'] === 0) {
            DB::transaction(fn () => $this->activate($intent, null));

            return ['action' => 'activated', ...$base];
        }

        $started = $this->payments->startStandalone($owner, 'subscription', $quote['amount_minor'], $plan->currency);
        $intent->update(['payment_id' => $started['payment']->id]);

        return ['action' => 'pay', ...$base, 'authorization_url' => $started['authorization_url'], 'payment_id' => $started['payment']->id];
    }

    /** Ends at the period end; benefits continue until then. Also drops a scheduled downgrade. */
    public function cancel(Vendor $vendor, User $owner): VendorSubscription
    {
        $this->authorizeOwner($owner, $vendor);
        $current = $this->current($vendor);
        if (! $current || ! $current->status->givesBenefits()) {
            throw ValidationException::withMessages(['subscription' => 'There is no plan to cancel.']);
        }

        $current->forceFill(['cancel_at_period_end' => true, 'scheduled_plan_id' => null, 'scheduled_interval' => null])->save();
        $this->notifyOwners($current, 'cancel_scheduled');

        return $current;
    }

    /** Undo a cancellation or a scheduled downgrade before the period ends. */
    public function resume(Vendor $vendor, User $owner): VendorSubscription
    {
        $this->authorizeOwner($owner, $vendor);
        $current = $this->current($vendor);
        if (! $current || ! $current->status->givesBenefits() || (! $current->cancel_at_period_end && ! $current->scheduled_plan_id)) {
            throw ValidationException::withMessages(['subscription' => 'Nothing to resume.']);
        }

        $current->forceFill(['cancel_at_period_end' => false, 'scheduled_plan_id' => null, 'scheduled_interval' => null])->save();

        return $current;
    }

    public function setAutoRenew(Vendor $vendor, User $owner, bool $on): VendorSubscription
    {
        $this->authorizeOwner($owner, $vendor);
        $current = $this->current($vendor) ?? throw ValidationException::withMessages(['subscription' => 'There is no plan to renew.']);
        $current->forceFill(['auto_renew' => $on])->save();

        return $current;
    }

    // ---- Payments ----------------------------------------------------------

    /**
     * A subscription payment succeeded (called from the payment webhook, inside its
     * transaction, once per payment; or directly when a credit covered the whole price).
     */
    public function activate(SubscriptionPayment $intent, ?WebhookResult $result): VendorSubscription
    {
        $intent = SubscriptionPayment::whereKey($intent->id)->lockForUpdate()->firstOrFail();
        if ($intent->status === 'paid') {
            return $intent->subscription;
        }

        $vendor = Vendor::findOrFail($intent->vendor_id);
        $current = VendorSubscription::current()->where('vendor_id', $vendor->id)->lockForUpdate()->first();
        $now = CarbonImmutable::now();
        $card = $this->cardDetails($result);

        $renewsCurrent = $intent->kind === 'renewal'
            && $current && $current->id === $intent->subscription_id && $current->status->givesBenefits();

        if ($renewsCurrent && $current->plan_id === $intent->plan_id && $current->interval === $intent->interval) {
            // Same plan: the next period follows straight on from the last.
            $start = $current->current_period_end;
            $current->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $intent->interval->addTo($start),
                'grace_ends_at' => null,
                ...$card,
            ])->save();
            $subscription = $current;
        } else {
            // New plan, upgrade, a scheduled downgrade starting, or paying again after Starter.
            $start = $renewsCurrent ? $current->current_period_end : $now;
            $subscription = $this->replaceCurrent($vendor, $intent->kind === 'upgrade' ? 'upgraded' : 'changed', [
                'plan_id' => $intent->plan_id,
                'interval' => $intent->interval,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $intent->interval->addTo($start),
                'auto_renew' => $current?->auto_renew ?? true,
                'payment_method' => $current?->payment_method,
                'authorization_code' => $current?->authorization_code,
                'card_last4' => $current?->card_last4,
                ...$card,
            ]);
        }

        $intent->forceFill([
            'status' => 'paid',
            'subscription_id' => $subscription->id,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
        ])->save();

        if ($intent->payment_id) {
            LedgerEntry::record('subscription', $intent->amount_minor, $intent->currency, vendorId: $vendor->id, paymentId: $intent->payment_id,
                memo: "Plan {$subscription->plan->key} ({$intent->kind})", idempotencyKey: "subscription:{$intent->id}");
        }

        $this->grantFeaturedCredits($subscription, $now);
        $this->syncVendor($vendor->id);

        $kind = $intent->kind === 'renewal' ? 'renewed' : 'activated';
        DB::afterCommit(fn () => $this->notifyOwners($subscription->refresh(), $kind));

        return $subscription;
    }

    /** A subscription payment failed: nothing changes yet; at the period end it goes to grace. */
    public function paymentFailed(Payment $payment): void
    {
        $intent = SubscriptionPayment::where('payment_id', $payment->id)->first();
        if (! $intent || $intent->status !== 'pending') {
            return;
        }
        $intent->update(['status' => 'failed']);

        $subscription = $intent->subscription_id ? VendorSubscription::find($intent->subscription_id) : null;
        if ($subscription && $payment->method === 'card') {
            DB::afterCommit(fn () => $this->notifyOwners($subscription, 'card_declined'));
        }
    }

    /** Charge the saved card for the next period (scheduled; once per period). */
    public function renewByCard(VendorSubscription $subscription): ?Payment
    {
        if (! $subscription->canChargeCard() || $subscription->cancel_at_period_end) {
            return null;
        }

        $plan = $subscription->renewalPlan();
        $interval = $subscription->renewalInterval();
        $owner = $this->owners($subscription->vendor)->first();
        if (! $owner) {
            return null;
        }

        $intent = SubscriptionPayment::create([
            'vendor_id' => $subscription->vendor_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'kind' => 'renewal',
            'amount_minor' => $plan->price($interval),
            'currency' => $plan->currency,
        ]);

        $payment = $this->payments->chargeSaved($owner, 'subscription', $intent->amount_minor, $intent->currency,
            $subscription->authorization_code, "renewal:{$subscription->id}:{$subscription->current_period_end->timestamp}");
        $intent->update(['payment_id' => $payment->id]);

        // The charge may already be final; apply it if the webhook path didn't (it couldn't
        // see this intent before payment_id was set).
        if ($payment->status === 'succeeded' && $intent->fresh()->status === 'pending') {
            DB::transaction(fn () => $this->activate($intent, null));
        } elseif ($payment->status === 'failed') {
            $this->paymentFailed($payment);
        }

        return $payment;
    }

    // ---- Period ends (scheduled) -----------------------------------------

    /** The period ended without a renewal: cancelled or trial → Starter; unpaid → grace. */
    public function endPeriod(VendorSubscription $subscription): void
    {
        $notice = DB::transaction(function () use ($subscription) {
            $subscription = VendorSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (! $subscription->is_current || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)
                || $subscription->current_period_end->isFuture()) {
                return null;
            }

            [$status, $reason, $notice] = match (true) {
                $subscription->cancel_at_period_end => [SubscriptionStatus::Cancelled, 'cancelled', 'downgraded'],
                $subscription->is_trial => [SubscriptionStatus::Expired, 'trial_ended', 'trial_ended'],
                default => [SubscriptionStatus::Grace, null, 'grace_started'],
            };

            if ($status === SubscriptionStatus::Grace) {
                $subscription->forceFill([
                    'status' => $status,
                    'grace_ends_at' => $subscription->current_period_end->addDays(config('eventapp.subscriptions.grace_days')),
                ])->save();
            } else {
                $this->end($subscription, $status, $reason);
            }
            $this->syncVendor($subscription->vendor_id);

            return [$subscription, $notice];
        });

        if ($notice) {
            $this->notifyOwners(...$notice);
        }
    }

    /** Grace is over and nothing was paid: Starter. Listings, bookings and posts are untouched. */
    public function endGrace(VendorSubscription $subscription): void
    {
        $ended = DB::transaction(function () use ($subscription) {
            $subscription = VendorSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if ($subscription->status !== SubscriptionStatus::Grace || $subscription->grace_ends_at->isFuture()) {
                return null;
            }
            $this->end($subscription, SubscriptionStatus::Expired, 'expired');
            $this->syncVendor($subscription->vendor_id);

            return $subscription;
        });

        if ($ended) {
            $this->notifyOwners($ended, 'downgraded');
        }
    }

    /**
     * Featured weeks for the month containing $at, for paid plans. Granted once per month
     * (yearly plans get them monthly too). Returns how many were granted now.
     */
    public function grantFeaturedCredits(VendorSubscription $subscription, CarbonImmutable $at): int
    {
        $weeks = $subscription->plan->featured_weeks_per_month;
        if ($weeks <= 0 || $subscription->is_trial || $subscription->status !== SubscriptionStatus::Active) {
            return 0;
        }

        $period = $at->setTimezone(config('eventapp.business_timezone'))->format('Y-m');
        $granted = 0;
        foreach (range(1, $weeks) as $sequence) {
            try {
                FeaturedCredit::create([
                    'vendor_id' => $subscription->vendor_id,
                    'subscription_id' => $subscription->id,
                    'granted_for_period' => $period,
                    'sequence' => $sequence,
                    'expires_at' => $at->addDays(config('eventapp.subscriptions.featured_credit_valid_days')),
                ]);
                $granted++;
            } catch (UniqueConstraintViolationException) {
                // Already granted this month.
            }
        }

        return $granted;
    }

    // ---- Platform team ---------------------------------------------------

    /** Push the current period's end out (and leave grace if it was in it). */
    public function extend(VendorSubscription $subscription, int $days, string $reason, ?User $admin): VendorSubscription
    {
        DB::transaction(function () use ($subscription, $days, $reason, $admin) {
            $from = $subscription->status === SubscriptionStatus::Grace ? CarbonImmutable::now() : $subscription->current_period_end;
            $subscription->forceFill([
                'current_period_end' => $from->addDays($days),
                'status' => $subscription->status === SubscriptionStatus::Grace ? SubscriptionStatus::Active : $subscription->status,
                'grace_ends_at' => null,
            ])->save();
            $this->recordAdjustment($subscription, 'extend', $days, $reason, $admin);
            $this->syncVendor($subscription->vendor_id);
        });

        return $subscription;
    }

    /** Give a vendor a plan for free for $days (no payment; it then renews like any other). */
    public function comp(Vendor $vendor, Plan $plan, int $days, string $reason, ?User $admin): VendorSubscription
    {
        return DB::transaction(function () use ($vendor, $plan, $days, $reason, $admin) {
            $now = CarbonImmutable::now();
            $subscription = $this->replaceCurrent($vendor, 'changed', [
                'plan_id' => $plan->id,
                'interval' => BillingInterval::Month,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $now,
                'current_period_end' => $now->addDays($days),
                'auto_renew' => false,
            ]);
            $this->recordAdjustment($subscription, 'comp', $days, $reason, $admin);
            $this->syncVendor($vendor->id);

            return $subscription;
        });
    }

    // ---------------------------------------------------------------------

    /** Cache the plan on the vendor row for ranking queries. */
    public function syncVendor(string $vendorId): void
    {
        $this->entitlements->forget($vendorId);
        $plan = $this->entitlements->plan($vendorId);

        Vendor::whereKey($vendorId)->toBase()->update([
            'plan_key' => $plan->key,
            'visibility_boost' => $plan->visibilityBoost(),
        ]);
    }

    /** @return Collection<int, User> */
    public function owners(Vendor $vendor)
    {
        return $vendor->users()->wherePivot('role', 'owner')->get();
    }

    public function notifyOwners(VendorSubscription $subscription, string $kind, array $extra = []): void
    {
        $subscription->loadMissing('vendor', 'plan');
        Notification::send($this->owners($subscription->vendor), new SubscriptionNotice($subscription, $kind, $extra));
    }

    /** End the current subscription (if any) and start $attributes as the vendor's current one. */
    private function replaceCurrent(Vendor $vendor, string $endReason, array $attributes): VendorSubscription
    {
        $current = VendorSubscription::current()->where('vendor_id', $vendor->id)->lockForUpdate()->first();
        if ($current) {
            $this->end($current, $current->status->givesBenefits() ? SubscriptionStatus::Cancelled : $current->status, $endReason);
        }

        $subscription = VendorSubscription::create(['vendor_id' => $vendor->id, 'is_current' => true, ...$attributes]);
        $this->syncVendor($vendor->id);

        return $subscription->load('plan');
    }

    private function end(VendorSubscription $subscription, SubscriptionStatus $status, ?string $reason): void
    {
        $subscription->forceFill([
            'status' => $status,
            'is_current' => false,
            'ended_at' => now(),
            'end_reason' => $reason ?? $subscription->end_reason,
        ])->save();
    }

    /** @return array<string, mixed> card details to save from a successful payment */
    private function cardDetails(?WebhookResult $result): array
    {
        if (! $result) {
            return [];
        }
        $isCard = $result->method === 'card';
        $auth = $result->authorization;

        return [
            'payment_method' => $isCard ? 'card' : 'mobile_money',
            ...($isCard && ($auth['reusable'] ?? false) ? ['authorization_code' => $auth['code'], 'card_last4' => $auth['last4'] ?? null] : []),
            ...($isCard ? [] : ['authorization_code' => null, 'card_last4' => null]),
        ];
    }

    private function recordAdjustment(VendorSubscription $subscription, string $kind, int $days, string $reason, ?User $admin): void
    {
        DB::table('subscription_adjustments')->insert([
            'subscription_id' => $subscription->id,
            'admin_id' => $admin?->id,
            'kind' => $kind,
            'days' => $days,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function authorizeOwner(User $user, Vendor $vendor): void
    {
        if (! Gate::forUser($user)->allows('own', $vendor)) {
            throw new AuthorizationException('Only the owners can change the plan or pay for it.');
        }
    }
}

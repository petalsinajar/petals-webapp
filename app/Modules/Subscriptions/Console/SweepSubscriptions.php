<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Modules\Subscriptions\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hourly, in this order: charge saved cards before the period ends, remind mobile money
 * payers (3 and 1 days before), end periods (cancelled/trial → Starter, unpaid → grace),
 * end grace (→ Starter), and grant this month's featured weeks.
 */
class SweepSubscriptions extends Command
{
    protected $signature = 'subscriptions:sweep';

    protected $description = 'Renew, remind, move to grace, downgrade, and grant featured weeks';

    public function handle(SubscriptionService $subscriptions): int
    {
        $now = CarbonImmutable::now();
        $config = config('eventapp.subscriptions');
        $counts = array_fill_keys(['charged', 'reminded', 'ended', 'downgraded', 'credits'], 0);
        $live = fn () => VendorSubscription::current()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])->with('plan', 'scheduledPlan', 'vendor');

        // 1. Cards: charged once per period, a few hours before it ends.
        $live()->where('status', SubscriptionStatus::Active)->where('cancel_at_period_end', false)
            ->where('payment_method', 'card')->where('auto_renew', true)->whereNotNull('authorization_code')
            ->where('current_period_end', '<=', $now->addHours($config['renew_card_hours_before']))
            ->where('current_period_end', '>', $now)
            ->each(function (VendorSubscription $s) use ($subscriptions, &$counts) {
                if ($this->claim($s, 'card_charge')) {
                    $subscriptions->renewByCard($s);
                    $counts['charged']++;
                }
            });

        // 2. Reminders for everyone else whose period is about to end (trials included).
        $stages = collect($config['reminder_days'])->sort()->values();
        $live()->where('cancel_at_period_end', false)
            ->where('current_period_end', '>', $now)
            ->where('current_period_end', '<=', $now->addDays($stages->max()))
            ->each(function (VendorSubscription $s) use ($subscriptions, $stages, $now, &$counts) {
                if ($s->canChargeCard() && ! $s->is_trial) {
                    return;
                }
                $daysLeft = $now->diffInHours($s->current_period_end) / 24;
                $stage = $stages->first(fn (int $d) => $daysLeft <= $d);
                if ($stage !== null && $this->claim($s, "reminder_{$stage}")) {
                    $subscriptions->notifyOwners($s, $s->is_trial ? 'trial_ending' : 'renewal_due', [
                        'days_left' => (int) ceil($daysLeft),
                        'amount_minor' => $s->renewalPlan()->price($s->renewalInterval()),
                    ]);
                    $counts['reminded']++;
                }
            });

        // 3. Periods that ended without a renewal.
        $live()->where('current_period_end', '<=', $now)->each(function (VendorSubscription $s) use ($subscriptions, &$counts) {
            $subscriptions->endPeriod($s);
            $counts['ended']++;
        });

        // 4. Grace over.
        VendorSubscription::current()->where('status', SubscriptionStatus::Grace)->where('grace_ends_at', '<=', $now)
            ->each(function (VendorSubscription $s) use ($subscriptions, &$counts) {
                $subscriptions->endGrace($s);
                $counts['downgraded']++;
            });

        // 5. Featured weeks for this month (idempotent per vendor and month).
        VendorSubscription::current()->where('status', SubscriptionStatus::Active)->where('is_trial', false)
            ->whereHas('plan', fn ($q) => $q->where('featured_weeks_per_month', '>', 0))
            ->with('plan')
            ->each(function (VendorSubscription $s) use ($subscriptions, $now, &$counts) {
                if ($granted = $subscriptions->grantFeaturedCredits($s, $now)) {
                    $subscriptions->notifyOwners($s, 'featured_credits', ['weeks' => $granted]);
                    $counts['credits'] += $granted;
                }
            });

        $this->info(collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }

    /** Once per subscription, kind and period. */
    private function claim(VendorSubscription $s, string $kind): bool
    {
        try {
            DB::table('subscription_notices')->insert([
                'subscription_id' => $s->id, 'kind' => $kind, 'period_end' => $s->current_period_end, 'created_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}

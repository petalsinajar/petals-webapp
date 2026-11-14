<?php

namespace App\Modules\Subscriptions\Notifications;

use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Support\Money;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Everything the owners hear about their plan. kind is one of: trial_started, trial_ending,
 * trial_ended, activated, renewed, renewal_due, card_declined, grace_started, downgraded,
 * change_scheduled, cancel_scheduled, featured_credits.
 */
class SubscriptionNotice extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $extra  e.g. days_left, amount_minor */
    public function __construct(
        public readonly VendorSubscription $subscription,
        public readonly string $kind,
        public readonly array $extra = [],
    ) {
        $this->onQueue(config('eventapp.subscriptions.queue'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function body(): string
    {
        $s = $this->subscription;
        $plan = $s->plan->name;
        $end = $s->current_period_end->setTimezone(config('eventapp.business_timezone'))->format('j M');
        $days = $this->extra['days_left'] ?? null;
        $amount = isset($this->extra['amount_minor']) ? Money::format($this->extra['amount_minor'], $s->plan->currency) : null;

        return match ($this->kind) {
            'trial_started' => "Your {$plan} trial has started. It runs until {$end}.",
            'trial_ending' => "Your {$plan} trial ends in {$days} ".str('day')->plural($days).'. Choose a plan to keep its benefits.',
            'trial_ended' => 'Your trial has ended. You are on Starter; your listings stay live.',
            'activated' => "You're on {$plan} until {$end}.",
            'renewed' => "{$plan} renewed until {$end}.",
            'renewal_due' => "{$plan} ends in {$days} ".str('day')->plural($days).". Pay {$amount} to renew.",
            'card_declined' => "We couldn't charge your card for {$plan}. Pay another way before {$end} to keep it.",
            'grace_started' => "{$plan} wasn't renewed. Its benefits continue until ".$s->grace_ends_at?->setTimezone(config('eventapp.business_timezone'))->format('j M').'; pay to keep them.',
            'downgraded' => 'Your plan ended and you are now on Starter. Your listings, bookings and reviews are untouched.',
            'change_scheduled' => "Your plan changes to {$s->scheduledPlan?->name} on {$end}.",
            'cancel_scheduled' => "{$plan} will end on {$end}. You can resume it until then.",
            'featured_credits' => 'You have new featured weeks. Choose when to use them.',
            default => "Your plan: {$plan}.",
        };
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            $this->subscription->vendor->name,
            $this->body(),
            route: "/vendors/{$this->subscription->vendor_id}/subscription",
            data: ['vendor_id' => $this->subscription->vendor_id],
            forBusiness: true,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_'.$this->kind,
            'target' => ['type' => 'vendor', 'id' => $this->subscription->vendor_id],
            'vendor' => ['id' => $this->subscription->vendor_id, 'name' => $this->subscription->vendor->name],
            'plan' => $this->subscription->plan->key,
            'body' => $this->body(),
            'pay_link' => in_array($this->kind, ['renewal_due', 'trial_ending', 'card_declined', 'grace_started'], true)
                ? str_replace('{vendor}', $this->subscription->vendor_id, config('eventapp.subscriptions.pay_link'))
                : null,
            ...$this->extra,
        ];
    }
}

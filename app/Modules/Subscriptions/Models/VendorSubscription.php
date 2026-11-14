<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Enums\BillingInterval;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor's plan over time. At most one row per vendor is current (is_current); a vendor
 * with none is on Starter. Plan changes end the current row and start a new one.
 */
class VendorSubscription extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['authorization_code'];

    protected $attributes = [
        'is_current' => true,
        'is_trial' => false,
        'cancel_at_period_end' => false,
        'auto_renew' => true,
    ];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'scheduled_interval' => BillingInterval::class,
            'status' => SubscriptionStatus::class,
            'is_current' => 'boolean',
            'is_trial' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'auto_renew' => 'boolean',
            'authorization_code' => 'encrypted',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'subscription_id');
    }

    /** @param  Builder<VendorSubscription>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /** The plan and interval the next period is billed at (a scheduled downgrade, or the same). */
    public function renewalPlan(): Plan
    {
        return $this->scheduledPlan ?? $this->plan;
    }

    public function renewalInterval(): BillingInterval
    {
        return $this->scheduled_interval ?? $this->interval;
    }

    public function canChargeCard(): bool
    {
        return $this->payment_method === 'card' && $this->authorization_code !== null && $this->auto_renew;
    }
}

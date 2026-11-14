<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Payments\Models\Payment;
use App\Modules\Subscriptions\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a subscription payment is for: a new plan, an upgrade (with the credit for the unused
 * part of the old one) or a renewal, and, once paid, the period it covered.
 */
class SubscriptionPayment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'credit_minor' => 0,
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'credit_minor' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<VendorSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'subscription_id');
    }
}

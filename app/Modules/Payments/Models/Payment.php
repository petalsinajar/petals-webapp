<?php

namespace App\Modules\Payments\Models;

use App\Modules\Bookings\Models\Checkout;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to collect money through the payment provider. Only webhooks mark it paid. */
class Payment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'allocations' => 'array', // { booking_id: amount_minor }
            'raw' => 'array',
            'amount_minor' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Checkout, $this> */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}

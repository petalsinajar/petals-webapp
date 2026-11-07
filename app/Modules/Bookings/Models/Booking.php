<?php

namespace App\Modules\Bookings\Models;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One order with one vendor for one event. Prices, titles and policies are snapshots
 * taken at booking time; later catalog edits never change a booking.
 */
class Booking extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'source' => 'app',
        'delivery_minor' => 0,
        'setup_minor' => 0,
        'discount_minor' => 0,
        'service_fee_minor' => 0,
        'damage_deposit_minor' => 0,
        'paid_minor' => 0,
        'refunded_minor' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'vendor_respond_by' => 'immutable_datetime',
            'pay_by' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'fulfilment' => 'array',
            'policy_snapshot' => 'array',
            'notes' => 'array',
            'condition' => 'array',
            'commission_percent' => 'float',
            'subtotal_minor' => 'integer',
            'delivery_minor' => 'integer',
            'setup_minor' => 'integer',
            'discount_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'deposit_due_minor' => 'integer',
            'damage_deposit_minor' => 'integer',
            'paid_minor' => 'integer',
            'refunded_minor' => 'integer',
            'commission_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Checkout, $this> */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<BookingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class);
    }

    /** @return HasMany<BookingStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(BookingStatusEvent::class)->orderBy('id');
    }

    /** Everything the customer owes in total, including the refundable damage deposit. */
    public function grandTotalMinor(): int
    {
        return $this->total_minor + $this->damage_deposit_minor;
    }

    public function balanceDueMinor(): int
    {
        return max(0, $this->grandTotalMinor() - $this->paid_minor);
    }

    public function depositOutstandingMinor(): int
    {
        return max(0, $this->deposit_due_minor - $this->paid_minor);
    }

    /** What the vendor earns once completed: everything except the service fee and commission. */
    public function vendorEarningMinor(): int
    {
        return $this->subtotal_minor + $this->delivery_minor + $this->setup_minor
            - $this->discount_minor - $this->commission_minor;
    }
}

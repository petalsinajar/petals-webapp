<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only money ledger. Never update or delete a row; correct mistakes with an
 * "adjustment" entry. Balances are sums over this table.
 */
class LedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }

    /**
     * Record an entry once. A repeated idempotency key is a no-op, so a webhook delivered
     * twice can never double-count money.
     */
    public static function record(
        string $type,
        int $amountMinor,
        string $currency,
        ?string $bookingId = null,
        ?string $vendorId = null,
        ?string $paymentId = null,
        ?string $memo = null,
        ?string $idempotencyKey = null,
    ): ?self {
        if ($idempotencyKey && static::where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        return static::create([
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'booking_id' => $bookingId,
            'vendor_id' => $vendorId,
            'payment_id' => $paymentId,
            'memo' => $memo,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}

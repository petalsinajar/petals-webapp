<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\VendorRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class VendorMember extends Pivot
{
    use HasUlids;

    protected $table = 'vendor_members';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['vendor_id', 'user_id', 'role', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['role' => VendorRole::class];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Modules\Social\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a user flagged for the platform team: target_type is a morph alias
 * (post, comment, user, vendor, item, review). Status: open | actioned | dismissed.
 */
class Report extends Model
{
    use HasUlids;

    protected $fillable = ['reporter_id', 'target_type', 'target_id', 'reason', 'details', 'status'];

    protected $attributes = ['status' => 'open'];

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}

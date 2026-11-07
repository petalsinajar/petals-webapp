<?php

namespace App\Modules\Events\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMember extends Model
{
    use HasUlids;

    protected $fillable = ['event_id', 'user_id', 'invite_phone', 'role', 'rsvp', 'plus_ones', 'invited_by'];

    protected $attributes = ['role' => 'guest', 'rsvp' => 'invited', 'plus_ones' => 0];

    protected function casts(): array
    {
        return ['plus_ones' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

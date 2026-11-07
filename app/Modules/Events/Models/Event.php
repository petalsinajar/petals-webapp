<?php

namespace App\Modules\Events\Models;

use App\Modules\Identity\Models\User;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const TYPES = ['wedding', 'birthday', 'corporate', 'other'];

    public const VISIBILITIES = ['public', 'followers', 'invite_only', 'private'];

    protected $attributes = [
        'visibility' => 'public',
        'status' => 'planning',
        'timezone' => 'Africa/Accra',
        'going_count' => 0,
        'posts_count' => 0,
        'bookings_count' => 0,
    ];

    protected $fillable = [
        'type', 'title', 'description', 'cover_url', 'starts_at', 'ends_at', 'timezone',
        'venue_name', 'venue_address', 'venue_lat', 'venue_lng', 'guest_count', 'budget_minor',
        'currency', 'visibility', 'theme', 'company', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'venue_lat' => 'float',
            'venue_lng' => 'float',
            'theme' => 'array',
            'company' => 'array',
            'guest_count' => 'integer',
            'budget_minor' => 'integer',
        ];
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return HasMany<EventMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(EventMember::class);
    }

    public function memberFor(User $user): ?EventMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }

    /** Host or co-host. */
    public function isHostedBy(User $user): bool
    {
        return $this->host_id === $user->id
            || $this->members()->where('user_id', $user->id)->whereIn('role', ['host', 'cohost'])->exists();
    }
}

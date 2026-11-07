<?php

namespace App\Modules\Identity\Models;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Package;
use Database\Factories\VendorFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use MongoDB\Laravel\Eloquent\HybridRelations;

class Vendor extends Model implements HasName
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, HasUlids, HybridRelations, Notifiable, SoftDeletes;

    protected $fillable = [
        'handle', 'name', 'bio', 'logo_url', 'cover_url', 'event_types', 'categories',
        'city', 'base_lat', 'base_lng', 'service_radius_km', 'contact', 'policies',
        'delivery', 'payout',
    ];

    protected $hidden = ['payout'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'status' => 'active',
        'verification_status' => 'pending',
        'service_radius_km' => 40,
        'rating_avg' => 0,
        'rating_count' => 0,
        'followers_count' => 0,
        'posts_count' => 0,
        'completed_bookings_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'categories' => 'array',
            'contact' => 'array',
            'policies' => 'array',
            'delivery' => 'array',
            'payout' => AsEncryptedArrayObject::class,
            'verified_at' => 'datetime',
            'base_lat' => 'float',
            'base_lng' => 'float',
            'rating_avg' => 'float',
        ];
    }

    protected static function newFactory(): VendorFactory
    {
        return VendorFactory::new();
    }

    /** @return HasMany<VendorMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(VendorMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vendor_members')
            ->using(VendorMember::class)
            ->withPivot(['id', 'role', 'status'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    /** Inventory in MongoDB (cross-database relation via HybridRelations). */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'vendor_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class, 'vendor_id');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Owners and managers: who gets booking requests and messages. */
    public function managers(): BelongsToMany
    {
        return $this->users()->wherePivotIn('role', ['owner', 'manager']);
    }

    public function policy(string $key, mixed $default = null): mixed
    {
        $defaults = [
            'deposit_percent' => config('eventapp.bookings.default_deposit_percent'),
            'buffer_hours' => config('eventapp.bookings.default_buffer_hours'),
            'lead_time_hours' => 48,
            'cancellation' => [
                ['days_before' => 14, 'refund_percent' => 100],
                ['days_before' => 3, 'refund_percent' => 50],
            ],
        ];

        return Arr::get($this->policies ?? [], $key, $defaults[$key] ?? $default);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }
}

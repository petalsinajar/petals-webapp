<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Media\Models\Media;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasAvatar, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'handle', 'display_name', 'phone', 'email', 'password', 'avatar_media_id',
        'bio', 'city', 'lat', 'lng', 'is_private', 'settings',
    ];

    protected $hidden = ['password', 'remember_token', 'phone', 'email', 'settings'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'status' => 'active',
        'is_admin' => false,
        'is_private' => false,
        'followers_count' => 0,
        'following_count' => 0,
        'posts_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_private' => 'boolean',
            'settings' => 'array',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return BelongsTo<Media, $this> */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** The avatar's thumbnail URL, built from the media when asked. */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar?->url('thumb'));
    }

    /** @return HasMany<VendorMember, $this> */
    public function vendorMemberships(): HasMany
    {
        return $this->hasMany(VendorMember::class)->where('status', 'active');
    }

    /** @return BelongsToMany<Vendor, $this> */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_members')
            ->using(VendorMember::class)
            ->withPivot(['id', 'role', 'status'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    public function roleIn(Vendor|string $vendor): ?VendorRole
    {
        $vendorId = $vendor instanceof Vendor ? $vendor->getKey() : $vendor;

        $role = $this->vendorMemberships()->where('vendor_id', $vendorId)->value('role');

        return match (true) {
            $role instanceof VendorRole => $role,
            is_string($role) => VendorRole::from($role),
            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    // ---- Filament -------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->is_admin,
            'vendor' => $this->vendorMemberships()->exists(),
            default => false,
        };
    }

    /** @return Collection<int, Vendor> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->vendors()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Vendor && $this->roleIn($tenant) !== null;
    }

    public function getFilamentName(): string
    {
        return $this->display_name ?: '@'.$this->handle;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }
}

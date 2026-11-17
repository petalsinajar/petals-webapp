<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets up a shop on a vendor's behalf (the platform team, at launch). The owner is found
 * or created by phone number, so when they sign in with an OTP the shop is already theirs.
 */
class VendorOnboarding
{
    public function __construct(private readonly HandleRegistry $handles) {}

    /**
     * @param  array{name: string, handle?: ?string, owner_phone: string, owner_name?: ?string, city?: ?string, bio?: ?string, categories?: ?array<int, string>, event_types?: ?array<int, string>}  $data
     */
    public function create(array $data): Vendor
    {
        $phone = PhoneNumber::normalize($data['owner_phone'])
            ?? throw new InvalidArgumentException('Invalid owner phone number.');

        return DB::transaction(function () use ($data, $phone) {
            $owner = $this->ownerFor($phone, $data['owner_name'] ?? null);

            $vendor = new Vendor([
                'name' => $data['name'],
                'city' => $data['city'] ?? null,
                'bio' => $data['bio'] ?? null,
                'categories' => ($data['categories'] ?? null) ?: null,
                'event_types' => ($data['event_types'] ?? null) ?: null,
            ]);
            $vendor->handle = filled($data['handle'] ?? null)
                ? HandleRegistry::normalize($data['handle'])
                : $this->handles->suggest($data['name']);
            $vendor->save();
            $this->handles->claim($vendor->handle, $vendor);
            $vendor->members()->create(['user_id' => $owner->id, 'role' => VendorRole::Owner, 'status' => 'active']);

            return $vendor;
        });
    }

    /** The account with this phone, or a new one, created the same way signing in would. */
    private function ownerFor(string $phone, ?string $name): User
    {
        $user = User::where('phone', $phone)->first();
        if ($user) {
            return $user;
        }

        $user = new User(['phone' => $phone, 'display_name' => trim((string) $name)]);
        $user->handle = $this->handles->suggest(filled($name) ? $name : 'user'.substr($phone, -4));
        $user->save();
        $this->handles->claim($user->handle, $user);

        return $user;
    }
}

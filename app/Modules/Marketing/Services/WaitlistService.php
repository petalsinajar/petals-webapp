<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Enums\WaitlistRole;
use App\Modules\Marketing\Models\WaitlistSignup;
use Illuminate\Database\UniqueConstraintViolationException;

class WaitlistService
{
    /**
     * Add someone to the waitlist. Joining twice with the same number and role
     * is not an error: the first sign-up is kept and returned.
     *
     * @param  array{name: string, whatsapp_phone: string, city: string, business_name?: ?string, category?: ?string, event_types?: ?array<int, string>, source?: ?array<string, string>}  $data
     * @return array{0: WaitlistSignup, 1: bool} the sign-up and whether it was created now
     */
    public function join(WaitlistRole $role, array $data): array
    {
        $existing = $this->find($role, $data['whatsapp_phone']);
        if ($existing) {
            return [$existing, false];
        }

        $isVendor = $role === WaitlistRole::Vendor;

        try {
            $signup = WaitlistSignup::create([
                'role' => $role,
                'name' => $data['name'],
                'whatsapp_phone' => $data['whatsapp_phone'],
                'city' => $data['city'],
                'business_name' => $isVendor ? ($data['business_name'] ?? null) : null,
                'category' => $isVendor ? ($data['category'] ?? null) : null,
                // Store "nothing chosen" as null rather than an empty list.
                'event_types' => ($data['event_types'] ?? null) ?: null,
                'source' => ($data['source'] ?? null) ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double-tap raced us to the insert.
            return [$this->find($role, $data['whatsapp_phone']), false];
        }

        return [$signup, true];
    }

    private function find(WaitlistRole $role, string $phone): ?WaitlistSignup
    {
        return WaitlistSignup::where('whatsapp_phone', $phone)->where('role', $role)->first();
    }
}

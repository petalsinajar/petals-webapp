<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;

/** The signed-in user, including private fields and the vendors they can act as. */
class MeResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'phone' => $this->phone,
            'email' => $this->email,
            // Phone accounts have no password until one is set for the web dashboard.
            'has_dashboard_sign_in' => filled($this->email) && filled($this->password),
            'settings' => $this->settings ?? (object) [],
            'is_admin' => $this->is_admin,
            'birthday' => $this->birthday?->toDateString(),
            'needs_profile' => blank($this->display_name),
            // Ask for a birthday until one is saved, so we can suggest planning their party.
            'needs_birthday' => $this->birthday === null,
            'vendors' => $this->vendors->map(fn ($vendor) => [
                'id' => $vendor->id,
                'handle' => $vendor->handle,
                'name' => $vendor->name,
                'logo_url' => $vendor->logo_url,
                'role' => $vendor->pivot->role instanceof \BackedEnum ? $vendor->pivot->role->value : $vendor->pivot->role,
            ])->values(),
        ];
    }
}

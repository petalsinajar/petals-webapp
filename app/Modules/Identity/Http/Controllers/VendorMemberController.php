<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Identity\Models\VendorMember;
use App\Modules\Subscriptions\Services\Entitlements;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorMemberController extends Controller
{
    /** GET /v1/vendors/{vendor}/members */
    public function index(Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);

        $members = $vendor->members()->with('user')->where('status', 'active')->get();

        return response()->json(['data' => $members->map(fn (VendorMember $m) => [
            'id' => $m->id,
            'role' => $m->role->value,
            'user' => (new UserResource($m->user))->resolve(),
        ])]);
    }

    /** POST /v1/vendors/{vendor}/members — add an existing app user by phone. */
    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('own', $vendor);

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'role' => ['required', Rule::enum(VendorRole::class)->except([VendorRole::Owner])],
        ]);

        $user = User::where('phone', PhoneNumber::normalize($data['phone']))->first();
        if (! $user) {
            throw ValidationException::withMessages(['phone' => 'Ask them to sign up to the app first, then add them.']);
        }

        // Changing an existing member's role is fine; a new seat needs room on the plan.
        $isMember = VendorMember::where(['vendor_id' => $vendor->id, 'user_id' => $user->id, 'status' => 'active'])->exists();
        if (! $isMember) {
            app(Entitlements::class)->ensureRoomFor($vendor, 'team');
        }

        $member = VendorMember::updateOrCreate(
            ['vendor_id' => $vendor->id, 'user_id' => $user->id],
            ['role' => $data['role'], 'status' => 'active'],
        );

        return response()->json(['id' => $member->id, 'role' => $member->role->value, 'user' => (new UserResource($user))->resolve()], 201);
    }

    /** DELETE /v1/vendors/{vendor}/members/{member} */
    public function destroy(Request $request, Vendor $vendor, VendorMember $member): JsonResponse
    {
        Gate::authorize('own', $vendor);
        abort_unless($member->vendor_id === $vendor->id, 404);

        if ($member->role === VendorRole::Owner
            && $vendor->members()->where('role', VendorRole::Owner)->where('status', 'active')->count() === 1) {
            throw ValidationException::withMessages(['member' => 'A vendor needs at least one owner.']);
        }

        $member->update(['status' => 'removed']);

        // Revoke immediately: their open dashboard sessions lose access on the next request.
        return response()->json(['message' => 'Member removed.']);
    }
}

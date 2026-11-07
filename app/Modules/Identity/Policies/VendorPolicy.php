<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;

/**
 * Vendor permissions are read from vendor_members on every request,
 * so removing a staff member takes effect immediately.
 */
class VendorPolicy
{
    /** Any active member: see the schedule, block dates, mark delivered/returned, chat. */
    public function operate(User $user, Vendor $vendor): bool
    {
        return $vendor->status === 'active' && $user->roleIn($vendor) !== null;
    }

    /** Owners and managers: inventory, pricing, bookings, posting as the business. */
    public function manage(User $user, Vendor $vendor): bool
    {
        return $vendor->status === 'active' && (bool) $user->roleIn($vendor)?->canManage();
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $this->manage($user, $vendor);
    }

    /** Owners only: payouts, policies, members. */
    public function own(User $user, Vendor $vendor): bool
    {
        return (bool) $user->roleIn($vendor)?->isOwner();
    }
}

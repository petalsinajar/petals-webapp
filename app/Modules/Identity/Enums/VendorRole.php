<?php

namespace App\Modules\Identity\Enums;

enum VendorRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /** Inventory, pricing, bookings, quotes, posting as the business. */
    public function canManage(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    /** Payouts, policies, members. */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}

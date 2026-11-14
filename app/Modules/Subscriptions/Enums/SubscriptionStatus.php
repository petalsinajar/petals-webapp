<?php

namespace App\Modules\Subscriptions\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';         // unpaid after the period ended: benefits continue for a few days
    case Expired = 'expired';     // ended unpaid (or the trial ended): the vendor is on Starter
    case Cancelled = 'cancelled'; // ended by the owner, or replaced by another plan

    /** The plan's benefits apply. */
    public function givesBenefits(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::Grace], true);
    }
}

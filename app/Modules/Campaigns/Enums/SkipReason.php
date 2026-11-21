<?php

namespace App\Modules\Campaigns\Enums;

/** Why a member of the audience didn't get a message on a channel. */
enum SkipReason: string
{
    case OptedOut = 'opted_out';           // turned this channel/category off in the app
    case SmsStopped = 'sms_stopped';       // replied STOP
    case NoDevice = 'no_device';           // push: no signed-in device
    case NoVerifiedPhone = 'no_verified_phone';
    case FrequencyCap = 'frequency_cap';
    case Inactive = 'inactive';            // suspended or deleted since the audience was built

    public function label(): string
    {
        return match ($this) {
            self::OptedOut => 'Opted out',
            self::SmsStopped => 'Replied STOP',
            self::NoDevice => 'No device',
            self::NoVerifiedPhone => 'No verified phone',
            self::FrequencyCap => 'Frequency cap',
            self::Inactive => 'Suspended or deleted',
        };
    }
}

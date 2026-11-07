<?php

namespace App\Modules\Marketing\Enums;

enum WaitlistRole: string
{
    case Host = 'host';
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::Host => 'Host',
            self::Vendor => 'Vendor',
        };
    }
}

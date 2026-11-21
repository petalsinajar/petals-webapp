<?php

namespace App\Modules\Campaigns\Enums;

use Carbon\CarbonImmutable;

enum Recurrence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function next(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $at->addDay(),
            self::Weekly => $at->addWeek(),
        };
    }
}

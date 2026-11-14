<?php

namespace App\Modules\Subscriptions\Enums;

use Carbon\CarbonImmutable;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function addTo(CarbonImmutable $start): CarbonImmutable
    {
        return $this === self::Month ? $start->addMonthNoOverflow() : $start->addYearNoOverflow();
    }
}

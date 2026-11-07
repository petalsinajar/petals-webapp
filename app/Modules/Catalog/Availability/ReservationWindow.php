<?php

namespace App\Modules\Catalog\Availability;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns a rental window into the calendar days it occupies.
 * Availability is tracked per day, which fits event rentals.
 */
final class ReservationWindow
{
    /**
     * Add the vendor's turnaround buffer on both sides.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function withBuffer(CarbonInterface $start, CarbonInterface $end, int $bufferHours): array
    {
        return [
            CarbonImmutable::instance($start)->subHours($bufferHours),
            CarbonImmutable::instance($end)->addHours($bufferHours),
        ];
    }

    /**
     * Days touched by [start, end) in the business timezone. An end exactly at midnight
     * does not occupy the following day.
     *
     * @return list<string>
     */
    public static function days(CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        $tz = $timezone ?? config('eventapp.business_timezone', 'UTC');
        $cursor = CarbonImmutable::instance($start)->setTimezone($tz)->startOfDay();
        $last = CarbonImmutable::instance($end)->setTimezone($tz)->subSecond()->startOfDay();

        $days = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            $days[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $days;
    }
}

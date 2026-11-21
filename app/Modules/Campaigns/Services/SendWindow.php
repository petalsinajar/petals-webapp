<?php

namespace App\Modules\Campaigns\Services;

use Carbon\CarbonImmutable;

/**
 * Quiet hours (21:00–08:00 by default) in the audience's timezone. Nothing is sent inside
 * them: "send now" waits for the morning, and a schedule inside them is refused.
 */
final class SendWindow
{
    public static function timezone(): string
    {
        return (string) config('eventapp.campaigns.audience_timezone');
    }

    public static function isQuiet(CarbonImmutable $at): bool
    {
        $local = $at->setTimezone(self::timezone())->format('H:i');
        ['start' => $start, 'end' => $end] = config('eventapp.campaigns.quiet_hours');

        return $start <= $end
            ? $local >= $start && $local < $end
            : $local >= $start || $local < $end; // wraps midnight
    }

    /** $at itself when it's allowed, else when the quiet hours end (in UTC, like every timestamp). */
    public static function nextAllowed(CarbonImmutable $at): CarbonImmutable
    {
        if (! self::isQuiet($at)) {
            return $at;
        }
        [$hour, $minute] = array_map('intval', explode(':', config('eventapp.campaigns.quiet_hours.end')));
        $local = $at->setTimezone(self::timezone());
        $end = $local->setTime($hour, $minute);
        if ($end <= $local) {
            $end = $end->addDay();
        }

        return $end->utc();
    }
}

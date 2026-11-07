<?php

namespace App\Modules\Recommendations\Enums;

enum RecommendationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Opened = 'opened';
    case Dismissed = 'dismissed';
    case Booked = 'booked';

    /** Still shown to the host as a live suggestion. */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Pending, self::Sent, self::Opened], true);
    }
}

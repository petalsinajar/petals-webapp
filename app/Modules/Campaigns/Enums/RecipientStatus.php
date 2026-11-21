<?php

namespace App\Modules\Campaigns\Enums;

enum RecipientStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /** Counts towards frequency caps: it reached (or is reaching) the person. */
    public static function reached(): array
    {
        return [self::Sent->value, self::Delivered->value];
    }
}

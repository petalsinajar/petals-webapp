<?php

namespace App\Modules\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    /** Over the approval threshold: waits for a super admin. */
    case PendingApproval = 'pending_approval';
    /** Due at scheduled_at (recurring campaigns come back here after each run). */
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    /** Content and audience can still change. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Scheduled], true);
    }
}

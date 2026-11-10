<?php

namespace App\Modules\Media\Enums;

/**
 * pending → uploaded → processing → ready
 *                                 ↘ failed (bad file, provider error) / rejected (moderation)
 */
enum MediaStatus: string
{
    /** Upload URL handed out; nothing received yet. */
    case Pending = 'pending';

    /** The file is in storage (or with the video provider) and checked; processing is queued. */
    case Uploaded = 'uploaded';

    case Processing = 'processing';

    case Ready = 'ready';

    case Failed = 'failed';

    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Rejected], true);
    }

    /** Received and on its way to ready: posts may use it and publish once it is. */
    public function isOnItsWay(): bool
    {
        return in_array($this, [self::Uploaded, self::Processing], true);
    }
}

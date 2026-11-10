<?php

namespace App\Modules\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A media will never become ready: unreadable, provider error, or rejected by moderation. */
class MediaFailed
{
    use Dispatchable;

    public function __construct(public readonly string $mediaId) {}
}

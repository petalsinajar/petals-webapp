<?php

namespace App\Modules\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A media finished processing and passed moderation. */
class MediaReady
{
    use Dispatchable;

    public function __construct(public readonly string $mediaId) {}
}

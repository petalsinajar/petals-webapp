<?php

namespace App\Modules\Media\Moderation;

use App\Modules\Media\Contracts\ContentModeration;
use App\Modules\Media\Data\ModerationVerdict;
use App\Modules\Media\Models\Media;

/** No moderation service yet: everything is approved. Bind a real one in AppServiceProvider. */
class AllowAllModeration implements ContentModeration
{
    public function review(Media $media): ModerationVerdict
    {
        return ModerationVerdict::approve();
    }
}

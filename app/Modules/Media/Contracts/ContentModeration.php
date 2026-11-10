<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\ModerationVerdict;
use App\Modules\Media\Models\Media;

/**
 * Called once per media, after processing and before it becomes ready. Images get their
 * processed renditions to look at (keys in $media->variants); videos their provider ref.
 */
interface ContentModeration
{
    public function review(Media $media): ModerationVerdict;
}

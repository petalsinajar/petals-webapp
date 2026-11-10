<?php

namespace App\Modules\Social\Listeners;

use App\Modules\Media\Events\MediaFailed;
use App\Modules\Media\Events\MediaReady;
use App\Modules\Media\Models\Media;
use App\Modules\Social\Services\PostService;

/** Posts made while their media was still processing publish once it's all ready. */
class PublishPostWhenMediaIsReady
{
    public function __construct(private readonly PostService $posts) {}

    public function handle(MediaReady|MediaFailed $event): void
    {
        $media = Media::find($event->mediaId);

        if ($media?->attachable_type === 'post') {
            $this->posts->mediaChanged($media->attachable_id);
        }
    }
}

<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delete media files and rows. Rows go only after their files, so a retry can finish the job. */
class DiscardMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** @param  list<string>  $mediaIds */
    public function __construct(public readonly array $mediaIds)
    {
        $this->onQueue(config('eventapp.media.queue'));
    }

    public function handle(MediaLibrary $library): void
    {
        foreach (Media::whereIn('id', $this->mediaIds)->whereNull('attachable_type')->get() as $media) {
            $library->purgeFiles($media);
            $media->delete();
        }
    }
}

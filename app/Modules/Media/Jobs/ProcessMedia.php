<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Services\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Make an uploaded media ready: renditions for images, the provider's status for video. */
class ProcessMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue(config('eventapp.media.queue'));
    }

    public function uniqueId(): string
    {
        return $this->mediaId;
    }

    public function handle(MediaProcessor $processor): void
    {
        $processor->process($this->mediaId);
    }

    public function failed(?Throwable $e): void
    {
        app(MediaProcessor::class)->giveUp($this->mediaId, 'processing_error');
    }
}

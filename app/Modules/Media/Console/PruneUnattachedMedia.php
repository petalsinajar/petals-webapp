<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibrary;
use Illuminate\Console\Command;

/** Daily: uploads nobody ever used (abandoned posts, changed minds) are deleted, files and rows. */
class PruneUnattachedMedia extends Command
{
    protected $signature = 'media:prune';

    protected $description = 'Delete media never attached to anything within the grace period';

    public function handle(MediaLibrary $library): int
    {
        $cutoff = now()->subHours(config('eventapp.media.unattached_ttl_hours'));
        $count = 0;

        Media::whereNull('attached_at')->where('created_at', '<', $cutoff)
            ->select('id')->chunkById(500, function ($chunk) use ($library, &$count) {
                $library->discard($chunk->modelKeys());
                $count += $chunk->count();
            });

        $this->info("Queued {$count} unattached uploads for deletion.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\Social\Console;

use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Post;
use Illuminate\Console\Command;

/**
 * Every few minutes: ranks recent public posts for Discover.
 * score = weighted engagement / (age in hours + 2) ^ 1.5
 * Shop taps weigh most, because they lead to rentals. Vendor posts get the plan's
 * visibility boost (score × (1 + boost), boost ≤ 0.1).
 */
class RescorePosts extends Command
{
    protected $signature = 'posts:rescore';

    protected $description = 'Recompute Discover ranking scores for recent posts';

    public function handle(): int
    {
        $since = now()->subDays(config('eventapp.feed.rescore_days'));
        $now = now();
        $count = 0;

        Post::published()->where('created_at', '>=', $since)
            ->select(['id', 'author_type', 'author_id', 'created_at', 'likes_count', 'comments_count', 'saves_count', 'shop_taps_count'])
            ->chunkById(500, function ($posts) use ($now, &$count) {
                // Vendors on paid plans get a small, capped lift (eventapp.visibility).
                $boosts = Vendor::whereIn('id', $posts->where('author_type', 'vendor')->pluck('author_id')->unique())
                    ->pluck('visibility_boost', 'id');

                foreach ($posts as $post) {
                    $hours = max(0, $post->created_at->diffInMinutes($now) / 60);
                    $engagement = $post->likes_count + 2 * $post->comments_count + 3 * $post->saves_count + 4 * $post->shop_taps_count + 1;
                    $boost = $post->author_type === 'vendor' ? (float) ($boosts[$post->author_id] ?? 0) : 0.0;
                    $score = round($engagement / (($hours + 2) ** 1.5) * (1 + $boost), 6);

                    Post::whereKey($post->id)->toBase()->update(['score' => $score]);
                    $count++;
                }
            });

        // Older posts fall out of Discover's top ranks.
        Post::where('created_at', '<', $since)->where('score', '>', 0)->toBase()->update(['score' => 0]);

        $this->info("Rescored {$count} posts.");

        return self::SUCCESS;
    }
}

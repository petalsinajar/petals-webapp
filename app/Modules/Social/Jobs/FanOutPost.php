<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Fan-out on write: copies a new post into each follower's Following feed.
 * Accounts above the follower limit are skipped and merged in at read time instead.
 */
class FanOutPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $postId) {}

    public function handle(): void
    {
        $post = Post::with('author')->find($this->postId);
        if (! $post || $post->status !== 'published' || ! in_array($post->visibility, ['public', 'followers'], true)) {
            return;
        }

        // The author always sees their own post.
        if ($post->author_type === Actor::USER) {
            DB::table('feed_items')->insertOrIgnore([
                'owner_id' => $post->author_id, 'post_id' => $post->id, 'created_at' => $post->created_at,
            ]);
        }

        if (($post->author?->followers_count ?? 0) > config('eventapp.feed.fanout_follower_limit')) {
            return;
        }

        DB::table('follows')
            ->where(['target_type' => $post->author_type, 'target_id' => $post->author_id, 'follower_type' => Actor::USER])
            ->select('follower_id')
            ->orderBy('id')
            ->chunk(1000, function ($followers) use ($post) {
                DB::table('feed_items')->insertOrIgnore($followers->map(fn ($f) => [
                    'owner_id' => $f->follower_id,
                    'post_id' => $post->id,
                    'created_at' => $post->created_at,
                ])->all());
            });
    }
}

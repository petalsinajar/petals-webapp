<?php

namespace App\Modules\Social\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FeedService
{
    /**
     * Following feed: fanned-out rows, plus recent posts from big accounts (which skip
     * fan-out) merged in at read time.
     */
    public function following(User $viewer, int $perPage = 20): CursorPaginator
    {
        $limit = config('eventapp.feed.fanout_follower_limit');
        $bigAccounts = DB::table('follows')
            ->where(['follower_type' => Actor::USER, 'follower_id' => $viewer->id])
            ->get(['target_type', 'target_id'])
            ->groupBy('target_type')
            ->map(fn ($rows, $type) => ($type === Actor::USER ? User::query() : Vendor::query())
                ->whereIn('id', $rows->pluck('target_id'))
                ->where('followers_count', '>', $limit)
                ->pluck('id'));

        return $this->visible(Post::query(), $viewer)
            ->where(function (Builder $q) use ($viewer, $bigAccounts) {
                $q->whereIn('id', DB::table('feed_items')->select('post_id')->where('owner_id', $viewer->id));
                foreach ($bigAccounts as $type => $ids) {
                    if ($ids->isNotEmpty()) {
                        $q->orWhere(fn (Builder $q) => $q->where('author_type', $type)->whereIn('author_id', $ids)
                            ->whereIn('visibility', ['public', 'followers']));
                    }
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /** Discover: public posts ranked by score, filterable by event type. */
    public function discover(?User $viewer, ?string $eventType, int $perPage = 20): CursorPaginator
    {
        return $this->visible(Post::query(), $viewer)
            ->where('visibility', 'public')
            ->when($eventType, fn ($q) => $q->where('event_type', $eventType))
            ->orderByDesc('score')->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /** Everything posted to one event, newest first. */
    public function event(Event $event, User $viewer, int $perPage = 20): CursorPaginator
    {
        return $this->visible(Post::query(), $viewer)
            ->where('event_id', $event->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /** A profile grid: one author's posts. */
    public function author(Actor $author, ?User $viewer, int $perPage = 24): CursorPaginator
    {
        $isSelf = $viewer && $author->type === Actor::USER && $author->id === $viewer->id;
        $follows = $viewer && DB::table('follows')->where([
            'follower_type' => Actor::USER, 'follower_id' => $viewer->id,
            'target_type' => $author->type, 'target_id' => $author->id,
        ])->exists();

        return $this->visible(Post::query(), $viewer)
            ->where(['author_type' => $author->type, 'author_id' => $author->id])
            ->whereIn('visibility', $isSelf ? ['public', 'followers', 'event_members'] : ($follows ? ['public', 'followers'] : ['public']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /** "Seen in": posts with an approved tag pointing at an item. */
    public function taggedItem(string $itemId, ?User $viewer, int $perPage = 12): CursorPaginator
    {
        return $this->visible(Post::query(), $viewer)
            ->where('visibility', 'public')
            ->whereIn('id', DB::table('post_tags')->select('post_id')->where(['target_type' => 'item', 'target_id' => $itemId, 'status' => 'approved']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Published, not from blocked accounts.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function visible(Builder $query, ?User $viewer): Builder
    {
        $query->published();

        if ($viewer) {
            $query->whereNotExists(fn ($q) => $q->from('blocks')
                ->where('blocks.blocker_id', $viewer->id)
                ->whereColumn('blocks.blocked_type', 'posts.author_type')
                ->whereColumn('blocks.blocked_id', 'posts.author_id'));
        }

        return $query;
    }
}

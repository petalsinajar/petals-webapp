<?php

namespace App\Modules\Social\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Identity\Http\Resources\ActorResource;
use App\Modules\Identity\Models\User;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTag;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns posts into the feed card JSON in a fixed number of queries, whatever the page size:
 * authors, events, tags, item cards (one MongoDB query), my likes, follow state.
 */
class PostPresenter
{
    public function __construct(private readonly CatalogReader $catalog) {}

    /**
     * @param  iterable<Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function many(iterable $posts, ?User $viewer): array
    {
        $posts = EloquentCollection::make($posts);
        if ($posts->isEmpty()) {
            return [];
        }

        $posts->loadMissing(['author', 'event:id,title,type,starts_at', 'tags']);

        $itemIds = $posts->flatMap(fn (Post $p) => $p->tags->where('target_type', 'item')->pluck('target_id'))->unique()->values()->all();
        $items = $this->catalog->cards($itemIds);

        $liked = $viewer
            ? DB::table('reactions')->where(['actor_type' => Actor::USER, 'actor_id' => $viewer->id, 'reactable_type' => 'post'])
                ->whereIn('reactable_id', $posts->pluck('id'))->pluck('kind', 'reactable_id')
            : collect();

        return $posts->map(fn (Post $post) => $this->present($post, $viewer, $items, $liked))->values()->all();
    }

    public function one(Post $post, ?User $viewer): array
    {
        return $this->many([$post], $viewer)[0];
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  Collection<string, string>  $liked
     */
    private function present(Post $post, ?User $viewer, array $items, Collection $liked): array
    {
        $isAuthorTeam = $viewer && ($post->posted_by === $viewer->id);

        $tagsByMedia = $post->tags
            ->filter(fn (PostTag $t) => $t->status === 'approved' || $isAuthorTeam)
            ->groupBy('media_index');

        return [
            'id' => $post->id,
            'author' => $post->author ? (new ActorResource($post->author))->resolve() : null,
            'event' => $post->event ? [
                'id' => $post->event->id,
                'title' => $post->event->title,
                'type' => $post->event->type,
            ] : null,
            'event_type' => $post->event_type,
            'kind' => $post->kind,
            'caption' => $post->caption,
            'hashtags' => $post->hashtags ?? [],
            'media' => collect($post->media)->map(fn (array $m, int $i) => [
                ...$m,
                'tags' => ($tagsByMedia->get($i) ?? collect())->map(fn (PostTag $t) => [
                    'id' => $t->id,
                    'x' => $t->x,
                    'y' => $t->y,
                    'target' => [
                        'type' => $t->target_type,
                        'id' => $t->target_id,
                        'variant_id' => $t->variant_id,
                        'item' => $t->target_type === 'item' ? ($items[$t->target_id] ?? null) : null,
                    ],
                    'status' => $t->status,
                ])->values(),
            ])->values(),
            'visibility' => $post->visibility,
            'counts' => [
                'likes' => $post->likes_count,
                'comments' => $post->comments_count,
                'saves' => $post->saves_count,
            ],
            'my_reaction' => $liked->get($post->id),
            'created_at' => $post->created_at->toIso8601String(),
        ];
    }
}

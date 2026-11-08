<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTag;
use App\Modules\Social\Notifications\PostLiked;
use App\Modules\Social\Services\PostPresenter;
use App\Modules\Social\Services\PostService;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    public function __construct(private readonly PostPresenter $presenter) {}

    /** POST /v1/posts */
    public function store(Request $request, PostService $posts): JsonResponse
    {
        $data = $request->validate([
            'as_vendor_id' => ['nullable', 'string', 'size:26'],
            'event_id' => ['nullable', 'string', 'size:26'],
            'kind' => ['nullable', Rule::in(['post', 'reel', 'showcase'])],
            'caption' => ['nullable', 'string', 'max:2200'],
            'visibility' => ['nullable', Rule::in(['public', 'followers', 'event_members'])],
            'media' => ['required', 'array', 'min:1', 'max:10'],
            'media.*.url' => ['required', 'url', 'max:500'],
            'media.*.type' => ['required', Rule::in(['image', 'video'])],
            'media.*.w' => ['nullable', 'integer', 'min:1'],
            'media.*.h' => ['nullable', 'integer', 'min:1'],
            'media.*.blurhash' => ['nullable', 'string', 'max:100'],
            'media.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*.media_index' => ['required', 'integer', 'min:0'],
            'tags.*.x' => ['required', 'numeric', 'between:0,1'],
            'tags.*.y' => ['required', 'numeric', 'between:0,1'],
            'tags.*.target_type' => ['required', Rule::in(['item', 'vendor', 'user'])],
            'tags.*.target_id' => ['required', 'string', 'max:26'],
            'tags.*.variant_id' => ['nullable', 'string', 'size:24'],
        ]);

        $post = $posts->create($request->user(), $data);

        return response()->json($this->presenter->one($post, $request->user()), 201);
    }

    /** GET /v1/posts/{post} — public route; the viewer may be anonymous. */
    public function show(Request $request, Post $post): JsonResponse
    {
        self::authorizeView($request, $post);

        return response()->json($this->presenter->one($post, $request->user()));
    }

    /**
     * Who may read a post (and its comments): anyone once it's published,
     * except event-only posts, which stay with the event's members.
     * Guests are denied by the event policy, so those answer 403.
     */
    public static function authorizeView(Request $request, Post $post): void
    {
        abort_unless($post->status === 'published' || $post->posted_by === $request->user()?->id, 404);
        if ($post->visibility === 'event_members' && $post->event) {
            Gate::authorize('view', $post->event);
        }
    }

    /** DELETE /v1/posts/{post} */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        $canDelete = $post->posted_by === $user->id
            || ($post->author_type === Actor::VENDOR && $user->roleIn($post->author_id)?->canManage())
            || $user->is_admin;
        abort_unless($canDelete, 403);

        DB::transaction(function () use ($post) {
            $post->update(['status' => 'removed']);
            $post->delete();
            $post->author?->decrement('posts_count');
            $post->event?->decrement('posts_count');
        });

        return response()->json(['message' => 'Post deleted.']);
    }

    /** PUT /v1/posts/{post}/reaction — body: { kind: like|love|fire } */
    public function react(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['kind' => ['sometimes', Rule::in(['like', 'love', 'fire'])]]);
        $user = $request->user();
        abort_unless($post->status === 'published', 404);

        $created = DB::transaction(function () use ($post, $user, $data) {
            $key = ['actor_type' => Actor::USER, 'actor_id' => $user->id, 'reactable_type' => 'post', 'reactable_id' => $post->id];

            $inserted = DB::table('reactions')->insertOrIgnore([
                ...$key, 'id' => (string) Str::ulid(), 'kind' => $data['kind'] ?? 'like',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($inserted) {
                $post->increment('likes_count');
            } else {
                DB::table('reactions')->where($key)->update(['kind' => $data['kind'] ?? 'like', 'updated_at' => now()]);
            }

            return (bool) $inserted;
        });

        if ($created && ! ($post->author_type === Actor::USER && $post->author_id === $user->id)) {
            $recipient = $post->author_type === Actor::USER ? $post->author : User::find($post->posted_by);
            $recipient?->notify(new PostLiked($post, $user));
        }

        return response()->json(['likes' => $post->refresh()->likes_count, 'my_reaction' => $data['kind'] ?? 'like']);
    }

    /** DELETE /v1/posts/{post}/reaction */
    public function unreact(Request $request, Post $post): JsonResponse
    {
        DB::transaction(function () use ($post, $request) {
            $deleted = DB::table('reactions')->where([
                'actor_type' => Actor::USER, 'actor_id' => $request->user()->id,
                'reactable_type' => 'post', 'reactable_id' => $post->id,
            ])->delete();

            if ($deleted) {
                $post->decrement('likes_count');
            }
        });

        return response()->json(['likes' => $post->refresh()->likes_count, 'my_reaction' => null]);
    }

    /** POST /v1/posts/{post}/shop-taps — someone tapped a tag pin. Feeds ranking. */
    public function shopTap(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['tag_id' => ['required', 'string', 'size:26']]);
        abort_unless(PostTag::where(['id' => $data['tag_id'], 'post_id' => $post->id])->exists(), 404);

        $post->increment('shop_taps_count');

        return response()->json(['ok' => true]);
    }
}

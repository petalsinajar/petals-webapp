<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\ActorResource;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    /** GET /v1/posts/{post}/comments?parent_id= — top-level by default, or replies to one comment. */
    public function index(Request $request, Post $post): JsonResponse
    {
        // Comments are as private as their post.
        PostController::authorizeView($request, $post);

        $page = $post->comments()
            ->where('parent_id', $request->query('parent_id'))
            ->with('author')
            ->orderBy('created_at')->orderBy('id')
            ->cursorPaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Comment $c) => $this->present($c)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** POST /v1/posts/{post}/comments */
    public function store(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->status === 'published', 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'string', 'size:26'],
            'as_vendor_id' => ['nullable', 'string', 'size:26'],
        ]);

        $author = $request->user();
        if (! empty($data['as_vendor_id'])) {
            $author = Vendor::findOrFail($data['as_vendor_id']);
            Gate::authorize('manage', $author);
        }

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = $post->comments()->find($data['parent_id']);
            // One level of replies: replying to a reply attaches to its parent.
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'Comment not found.']);
            }
            $parent = $parent->parent_id ? $post->comments()->find($parent->parent_id) : $parent;
        }

        $comment = DB::transaction(function () use ($post, $author, $data, $parent, $request) {
            $actor = Actor::of($author);
            $comment = $post->comments()->create([
                'author_type' => $actor->type,
                'author_id' => $actor->id,
                'posted_by' => $request->user()->id,
                'parent_id' => $parent?->id,
                'body' => $data['body'],
            ]);

            $post->increment('comments_count');
            $parent?->increment('replies_count');

            return $comment;
        });

        return response()->json($this->present($comment->load('author')), 201);
    }

    /** DELETE /v1/comments/{comment} — the commenter or the post's author. */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $post = $comment->post;
        abort_unless($comment->posted_by === $user->id || $post->posted_by === $user->id || $user->is_admin, 403);

        DB::transaction(function () use ($comment, $post) {
            $comment->delete();
            $post->decrement('comments_count');
        });

        return response()->json(['message' => 'Comment deleted.']);
    }

    /** @return array<string, mixed> */
    private function present(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'parent_id' => $comment->parent_id,
            'author' => $comment->author ? (new ActorResource($comment->author))->resolve() : null,
            'body' => $comment->body,
            'likes' => $comment->likes_count,
            'replies' => $comment->replies_count,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}

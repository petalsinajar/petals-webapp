<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Services\FeedService;
use App\Modules\Social\Services\PostPresenter;
use App\Support\Actor;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feeds,
        private readonly PostPresenter $presenter,
    ) {}

    /** GET /v1/feed/following */
    public function following(Request $request): JsonResponse
    {
        return $this->respond($this->feeds->following($request->user()), $request);
    }

    /** GET /v1/feed/discover?event_type= */
    public function discover(Request $request): JsonResponse
    {
        $request->validate(['event_type' => ['nullable', EventType::rule()]]);

        return $this->respond($this->feeds->discover($request->user(), $request->query('event_type')), $request);
    }

    /** GET /v1/events/{event}/posts */
    public function event(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        return $this->respond($this->feeds->event($event, $request->user()), $request);
    }

    /** GET /v1/users/{user}/posts and GET /v1/vendors/{vendor}/posts (by id) */
    public function userPosts(Request $request, User $user): JsonResponse
    {
        return $this->respond($this->feeds->author(Actor::of($user), $request->user()), $request);
    }

    public function vendorPosts(Request $request, Vendor $vendor): JsonResponse
    {
        return $this->respond($this->feeds->author(Actor::of($vendor), $request->user()), $request);
    }

    /** GET /v1/items/{item}/posts — "Seen in" */
    public function itemPosts(Request $request, string $item): JsonResponse
    {
        return $this->respond($this->feeds->taggedItem($item, $request->user()), $request);
    }

    private function respond(CursorPaginator $page, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->presenter->many($page->items(), $request->user()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}

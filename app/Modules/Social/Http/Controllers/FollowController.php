<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\ActorResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Services\FollowService;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FollowController extends Controller
{
    public function __construct(private readonly FollowService $follows) {}

    /** POST /v1/follows — body: { target_type: user|vendor, target_id } */
    public function store(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $this->follows->follow($request->user(), $target);

        return response()->json(['following' => true, 'followers' => $target->refresh()->followers_count], 201);
    }

    /** DELETE /v1/follows — body: { target_type, target_id } */
    public function destroy(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $this->follows->unfollow($request->user(), $target);

        return response()->json(['following' => false, 'followers' => $target->refresh()->followers_count]);
    }

    /** GET /v1/{type}/{id}/followers — type is users or vendors */
    public function followers(Request $request, string $type, string $id): JsonResponse
    {
        $actorType = $type === 'vendors' ? Actor::VENDOR : Actor::USER;

        $page = \App\Modules\Social\Models\Follow::where(['target_type' => $actorType, 'target_id' => $id])
            ->with('follower')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($f) => $f->follower ? (new ActorResource($f->follower))->resolve() : null)->filter()->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    private function target(Request $request): User|Vendor
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in([Actor::USER, Actor::VENDOR])],
            'target_id' => ['required', 'string', 'size:26'],
        ]);

        return $data['target_type'] === Actor::USER
            ? User::where('status', 'active')->findOrFail($data['target_id'])
            : Vendor::where('status', 'active')->findOrFail($data['target_id']);
    }
}

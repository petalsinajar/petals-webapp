<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\HandleRegistry;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * GET /v1/users?q= — people by name or @username, e.g. to invite to an event. Public
     * fields only (never phone numbers); not the viewer themself. At most 20.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);
        $term = ltrim(trim($data['q']), '@');
        $like = '%'.addcslashes($term, '%_').'%';
        $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $users = User::query()
            ->where('status', 'active')
            ->whereKeyNot($request->user()->id)
            ->where(fn ($q) => $q->where('handle', $op, $like)->orWhere('display_name', $op, $like))
            ->orderByRaw('case when handle = ? then 0 else 1 end', [strtolower($term)])
            ->orderByDesc('followers_count')
            ->limit(20)
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'handle' => $u->handle,
            'display_name' => $u->display_name,
            'avatar_url' => $u->avatar_url,
        ])->values()]);
    }

    /** GET /v1/users/{handle} */
    public function show(Request $request, string $handle): UserResource
    {
        $user = User::where('handle', HandleRegistry::normalize($handle))->where('status', 'active')->firstOrFail();

        $user->setAttribute('is_following', DB::table('follows')->where([
            'follower_type' => Actor::USER, 'follower_id' => $request->user()->id,
            'target_type' => Actor::USER, 'target_id' => $user->id,
        ])->exists());

        return new UserResource($user);
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\HandleRegistry;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
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

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Services\AccountDeletion;
use App\Modules\Identity\Services\HandleRegistry;
use App\Modules\Media\Services\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MeController extends Controller
{
    public function __construct(
        private readonly HandleRegistry $handles,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * DELETE /v1/me { confirm: "DELETE" } — deletes the account (see AccountDeletion).
     * Refused with the reason while bookings are in progress or a business would be left
     * without an owner.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $request->validate(['confirm' => ['required', Rule::in(['DELETE'])]], [
            'confirm.in' => 'Type DELETE to confirm.',
        ]);
        $user = $request->user();

        if ($reason = $deletion->blocker($user)) {
            return response()->json(['message' => $reason, 'code' => 'account_in_use'], 409);
        }
        $deletion->delete($user);

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    /** GET /v1/me */
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->load('vendors'));
    }

    /** PATCH /v1/me */
    public function update(Request $request): MeResource
    {
        $user = $request->user();
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:80'],
            'handle' => ['sometimes', 'required', 'string', 'max:30'],
            'avatar_media_id' => ['sometimes', 'nullable', 'ulid'], // a ready image from POST /v1/uploads
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            // A birth date, not an event date: it is only checked for a plausible age. The
            // yearly occurrence comes from User::nextBirthday(). Defaults guard a stale config
            // cache, where a null would turn these into "after today".
            'birthday' => [
                'sometimes', 'nullable', 'date_format:Y-m-d',
                'before_or_equal:'.now()->subYears((int) config('eventapp.profile.min_age_years', 13))->toDateString(),
                'after:'.now()->subYears((int) config('eventapp.profile.max_age_years', 120))->toDateString(),
            ],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_private' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.notify.push' => ['sometimes', 'boolean'],
            'settings.notify.sms' => ['sometimes', 'boolean'],
            'settings.notify.suggestions' => ['sometimes', 'boolean'],
            'settings.notify.reviews' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['handle']) && HandleRegistry::normalize($data['handle']) !== $user->handle) {
            $this->handles->rename($user, $data['handle']);
        }
        unset($data['handle']);

        // Settings are patched, not replaced: turning one notification off keeps the others.
        if (isset($data['settings'])) {
            $data['settings'] = array_replace_recursive($user->settings ?? [], $data['settings']);
        }

        $avatar = array_key_exists('avatar_media_id', $data)
            ? $this->media->resolveOne($data['avatar_media_id'], $user, null, 'avatar_media_id', for: $user)
            : false;

        DB::transaction(function () use ($user, $data, $avatar) {
            $user->fill(['avatar_media_id' => $avatar === false ? $user->avatar_media_id : $avatar?->id, ...collect($data)->except('avatar_media_id')->all()])->save();
            if ($avatar !== false) {
                $this->media->attach([$avatar], $user);
                $this->media->releaseExcept($user, [$user->avatar_media_id]); // the old avatar goes
            }
        });

        return new MeResource($user->unsetRelation('avatar')->load('vendors'));
    }

    /**
     * PUT /v1/me/dashboard-credentials
     * Email + password for signing in to the vendor web dashboard (Filament).
     */
    public function dashboardCredentials(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);

        $user->forceFill(['email' => $data['email'], 'password' => $data['password']])->save();

        return response()->json(['message' => 'Dashboard sign-in saved.']);
    }

    /** POST /v1/me/devices — register a push token. */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'push_token' => ['required', 'string', 'max:500'],
        ]);

        Device::updateOrCreate(
            ['push_token' => $data['push_token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_seen_at' => now()],
        );

        return response()->json(['message' => 'Device registered.'], 201);
    }

    /** GET /v1/handles/{handle}/available */
    public function handleAvailable(string $handle): JsonResponse
    {
        return response()->json([
            'handle' => HandleRegistry::normalize($handle),
            'available' => $this->handles->isAvailable($handle),
        ]);
    }
}

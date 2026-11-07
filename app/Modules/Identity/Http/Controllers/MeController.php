<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Services\HandleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MeController extends Controller
{
    public function __construct(private readonly HandleRegistry $handles) {}

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
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_private' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.notify.push' => ['sometimes', 'boolean'],
            'settings.notify.sms' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['handle']) && HandleRegistry::normalize($data['handle']) !== $user->handle) {
            $this->handles->rename($user, $data['handle']);
        }
        unset($data['handle']);

        $user->fill($data)->save();

        return new MeResource($user->load('vendors'));
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

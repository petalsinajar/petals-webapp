<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\HandleRegistry;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\TokenService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
        private readonly HandleRegistry $handles,
    ) {}

    /** POST /v1/auth/otp */
    public function requestOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $this->otp->send($phone);

        return response()->json([
            'message' => 'Code sent.',
            'phone' => $phone,
            'expires_in' => config('eventapp.auth.otp_ttl_minutes') * 60,
        ]);
    }

    /** POST /v1/auth/verify — signs in, creating the account on first login. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $phone = $this->phone($request);

        $this->otp->verify($phone, $data['code']);

        [$user, $isNew] = DB::transaction(function () use ($phone) {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                return [$user, false];
            }

            $user = new User(['phone' => $phone, 'display_name' => '']);
            $user->handle = $this->handles->suggest('user'.substr($phone, -4));
            $user->phone_verified_at = now();
            $user->save();
            $this->handles->claim($user->handle, $user);

            return [$user, true];
        });

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['phone' => 'This account is suspended. Contact support.']);
        }

        $user->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            ...$this->tokens->issue($user, $data['device_name']),
            'is_new' => $isNew,
            'user' => new MeResource($user->load('vendors')),
        ]);
    }

    /** POST /v1/auth/refresh — body: { refresh_token } */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);
        $result = $this->tokens->refresh($data['refresh_token']);

        return response()->json($result['tokens']);
    }

    /** POST /v1/auth/logout — signs this device out. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $this->tokens->revokeDevice($request->user(), $token->name);

        return response()->json(['message' => 'Signed out.']);
    }

    private function phone(Request $request): string
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = PhoneNumber::normalize($request->string('phone'));

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
        }

        return $phone;
    }
}

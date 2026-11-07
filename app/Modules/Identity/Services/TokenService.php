<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues a short-lived access token plus a long-lived refresh token, both as Sanctum tokens.
 * Access tokens carry the "access" ability; refresh tokens only "refresh", so a refresh token
 * can never be used to call the API (routes require abilities:access).
 */
class TokenService
{
    /** @return array{access_token: string, refresh_token: string, expires_at: string, token_type: string} */
    public function issue(User $user, string $deviceName): array
    {
        $accessExpires = now()->addMinutes(config('eventapp.auth.access_token_minutes'));

        $access = $user->createToken($deviceName, ['access'], $accessExpires);
        $refresh = $user->createToken($deviceName.':refresh', ['refresh'], now()->addDays(config('eventapp.auth.refresh_token_days')));

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'expires_at' => $accessExpires->toIso8601String(),
        ];
    }

    /**
     * Rotate: the old refresh token is deleted and a fresh pair is issued.
     *
     * @return array{user: User, tokens: array<string, string>}
     */
    public function refresh(string $plainRefreshToken): array
    {
        $token = PersonalAccessToken::findToken($plainRefreshToken);

        if (! $token
            || ! $token->can('refresh')
            || ($token->expires_at && $token->expires_at->isPast())
            || ! $token->tokenable instanceof User
            || ! $token->tokenable->isActive()) {
            throw ValidationException::withMessages(['refresh_token' => 'Session expired. Please sign in again.']);
        }

        $user = $token->tokenable;
        $device = str($token->name)->beforeLast(':refresh')->toString();
        $token->delete();

        return ['user' => $user, 'tokens' => $this->issue($user, $device)];
    }

    /** Sign out of one device: removes its access and refresh tokens. */
    public function revokeDevice(User $user, string $deviceName): void
    {
        $user->tokens()->whereIn('name', [$deviceName, $deviceName.':refresh'])->delete();
    }
}

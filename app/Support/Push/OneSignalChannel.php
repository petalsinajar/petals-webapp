<?php

namespace App\Support\Push;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The `push` notification channel: sends through OneSignal to every device
 * where the user is signed in. The app links devices with
 * `OneSignal.login(user id)`, so the user id is OneSignal's external_id.
 *
 * Never throws: a failed push must not stop the other channels (the in-app
 * list keeps the notification). Logs a warning when it can't deliver (no
 * key, OneSignal refused, or no device matched) and skips users who turned
 * push off. `php artisan push:test {user}` checks one user end to end.
 */
class OneSignalChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof HasPushMessage) {
            return;
        }

        $config = config('eventapp.push.onesignal');
        if (blank($config['app_id'] ?? null) || blank($config['rest_api_key'] ?? null)) {
            Log::warning('Push skipped: ONESIGNAL_REST_API_KEY is not set', ['user' => $notifiable->getKey()]);

            return;
        }
        // Unset means on, as the app shows it.
        if ($notifiable instanceof User && data_get($notifiable->settings, 'notify.push', true) === false) {
            return;
        }

        $message = $notification->toPush($notifiable);
        if ($message === null) {
            return;
        }

        $response = $this->post((string) $notifiable->getKey(), $message, class_basename($notification));
        if ($response === null) {
            return;
        }

        if (! $response->successful()) {
            Log::warning('OneSignal rejected push', [
                'user' => $notifiable->getKey(),
                'status' => $response->status(),
                'errors' => $response->json('errors') ?? $response->body(),
            ]);

            return;
        }

        // OneSignal answers 200 even when no phone matched: the user never signed in on a
        // device with notifications allowed, or their subscription lapsed.
        if (blank($response->json('id')) || filled($response->json('errors'))) {
            Log::warning('Push reached no device', [
                'user' => $notifiable->getKey(),
                'errors' => $response->json('errors'),
            ]);
        }
    }

    /**
     * Sends one push to every device signed in as $userId. Null when OneSignal can't be
     * reached. Also used by `push:test`.
     */
    public function post(string $userId, PushMessage $message, string $type = 'test'): ?Response
    {
        $config = config('eventapp.push.onesignal');
        $payload = [
            'app_id' => $config['app_id'],
            'target_channel' => 'push',
            'include_aliases' => ['external_id' => [$userId]],
            'headings' => ['en' => $message->title],
            'contents' => ['en' => $message->body],
            'data' => array_filter([
                'route' => $message->route,
                'type' => $type,
                ...$message->data,
            ], fn ($value) => $value !== null),
            // Customers get the app's default look.
            ...($message->forBusiness ? self::businessLook($config) : []),
        ];

        try {
            return Http::baseUrl($config['url'])
                ->withHeaders(['Authorization' => self::authorization((string) $config['rest_api_key'])])
                ->acceptJson()
                ->timeout(10)
                ->post('notifications', $payload);
        } catch (ConnectionException $e) {
            Log::warning('OneSignal unreachable', ['user' => $userId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Android only (iOS always shows the app icon): the logo tinted in the vendor color
     * as the small icon, and the vendor-colored logo as the large one.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function businessLook(array $config): array
    {
        return array_filter([
            'android_accent_color' => $config['business_accent_color'] ?? null,
            'large_icon' => $config['business_large_icon'] ?? asset('images/push/business-logo.png'),
        ]);
    }

    /**
     * OneSignal's current keys (`os_v2_app_…`) go as `Key …`; legacy REST API keys as
     * `Basic …`. Stray spaces or quotes from .env are trimmed off.
     */
    public static function authorization(string $key): string
    {
        $key = trim($key, " \t\n\r\0\x0B\"'");

        return (str_starts_with($key, 'os_v2_') ? 'Key ' : 'Basic ').$key;
    }
}

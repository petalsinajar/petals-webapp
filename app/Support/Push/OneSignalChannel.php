<?php

namespace App\Support\Push;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The `push` notification channel: sends through OneSignal to every device
 * where the user is signed in. The app links devices with
 * `OneSignal.login(user id)`, so the user id is OneSignal's external_id.
 *
 * Never throws: a failed push must not stop the other channels (the in-app
 * list keeps the notification). Skips quietly when OneSignal isn't
 * configured, or the user turned push off in settings.
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

        $payload = [
            'app_id' => $config['app_id'],
            'target_channel' => 'push',
            'include_aliases' => ['external_id' => [(string) $notifiable->getKey()]],
            'headings' => ['en' => $message->title],
            'contents' => ['en' => $message->body],
            'data' => array_filter([
                'route' => $message->route,
                'type' => class_basename($notification),
                ...$message->data,
            ], fn ($value) => $value !== null),
        ];

        try {
            $response = Http::baseUrl($config['url'])
                ->withHeaders(['Authorization' => 'Key '.$config['rest_api_key']])
                ->acceptJson()
                ->timeout(10)
                ->post('notifications', $payload);
        } catch (ConnectionException $e) {
            Log::warning('OneSignal unreachable', ['user' => $notifiable->getKey(), 'error' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('OneSignal rejected push', [
                'user' => $notifiable->getKey(),
                'status' => $response->status(),
                'errors' => $response->json('errors') ?? $response->body(),
            ]);
        }
    }
}

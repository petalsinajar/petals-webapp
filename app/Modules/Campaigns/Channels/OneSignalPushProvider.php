<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Contracts\PushProvider;
use App\Modules\Campaigns\Data\PushResult;
use App\Support\Push\OneSignalChannel;
use App\Support\Push\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Campaign push through OneSignal, many users per request. The app links each device with
 * `OneSignal.login(user id)`, so users are OneSignal external ids. OneSignal reports the ids
 * it found no subscribed device for (`errors.invalid_aliases`), or a list of messages when
 * none matched at all.
 */
class OneSignalPushProvider implements PushProvider
{
    public function send(array $userIds, PushMessage $message): PushResult
    {
        $config = config('eventapp.push.onesignal');
        if (blank($config['app_id'] ?? null) || blank($config['rest_api_key'] ?? null)) {
            return new PushResult(false, error: 'ONESIGNAL_REST_API_KEY is not set');
        }

        try {
            $response = Http::baseUrl($config['url'])
                ->withHeaders(['Authorization' => OneSignalChannel::authorization((string) $config['rest_api_key'])])
                ->acceptJson()
                ->timeout(15)
                ->post('notifications', OneSignalChannel::payload($userIds, $message, 'campaign'));
        } catch (ConnectionException $e) {
            return new PushResult(false, error: 'OneSignal unreachable: '.$e->getMessage(), retryable: true);
        }

        if (! $response->successful()) {
            return new PushResult(
                false,
                error: 'OneSignal '.$response->status().': '.json_encode($response->json('errors') ?? $response->body()),
                retryable: $response->status() === 429 || $response->serverError(),
            );
        }

        $errors = $response->json('errors');
        $unreachable = match (true) {
            is_array($errors) && isset($errors['invalid_aliases']['external_id']) => array_values($errors['invalid_aliases']['external_id']),
            // ["All included players are not subscribed"]: nobody in the request had a device.
            is_array($errors) && array_is_list($errors) && $errors !== [] && blank($response->json('id')) => array_values($userIds),
            default => [],
        };

        return new PushResult(true, messageId: $response->json('id') ?: null, unreachableUserIds: $unreachable);
    }
}

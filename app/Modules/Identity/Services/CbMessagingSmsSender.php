<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * CB Messaging (cbmessaging.com), the Ghana SMS gateway rental-platform uses.
 * `POST sms/send-message` `{ to, msg, senderID }` with HTTP Basic auth;
 * any 2xx means accepted. Numbers go out as 233XXXXXXXXX, without the `+`.
 */
class CbMessagingSmsSender implements SmsSender
{
    /** Alphanumeric sender IDs are capped at 11 characters by the networks. */
    private const MAX_SENDER_ID = 11;

    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $senderId,
        private readonly string $baseUrl,
    ) {
        if ($key === '' || $secret === '') {
            throw new InvalidArgumentException('CB Messaging needs SMS_KEY and SMS_SECRET.');
        }
        if ($senderId === '' || strlen($senderId) > self::MAX_SENDER_ID) {
            throw new InvalidArgumentException('SMS_SENDER_ID must be 1-11 characters and registered with CB Messaging.');
        }
    }

    public static function fromConfig(): self
    {
        $config = config('eventapp.sms.cbmessaging');

        return new self(
            key: (string) ($config['key'] ?? ''),
            secret: (string) ($config['secret'] ?? ''),
            senderId: (string) ($config['sender_id'] ?? ''),
            baseUrl: (string) ($config['url'] ?? 'https://cbmessaging.com/api/v1/'),
        );
    }

    public function send(string $phone, string $message): void
    {
        $to = ltrim($phone, '+');

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withBasicAuth($this->key, $this->secret)
                ->acceptJson()
                ->timeout(10)
                ->post('sms/send-message', [
                    'to' => $to,
                    'msg' => $message,
                    'senderID' => $this->senderId,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('SMS gateway unreachable', ['to' => $to, 'error' => $e->getMessage()]);
            throw new SmsDeliveryFailed('CB Messaging unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            // Never log the message itself: it carries one-time codes.
            Log::warning('SMS gateway rejected message', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->json('message') ?? $response->body(),
            ]);
            throw new SmsDeliveryFailed("CB Messaging rejected the message ({$response->status()}).");
        }
    }
}

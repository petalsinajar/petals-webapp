<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Data\SmsResult;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Campaign SMS through CB Messaging, the gateway OTPs already use (same account and
 * registered sender ID, eventapp.sms.cbmessaging). The message id for delivery reports is
 * read from the reply if CB Messaging returns one.
 */
class CbMessagingSmsProvider implements SmsProvider
{
    use ReadsSmsWebhooks;

    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $senderId,
        private readonly string $baseUrl,
    ) {
        if ($key === '' || $secret === '' || $senderId === '' || strlen($senderId) > 11) {
            throw new InvalidArgumentException('CB Messaging needs SMS_KEY, SMS_SECRET and a registered SMS_SENDER_ID (1-11 characters).');
        }
    }

    public static function fromConfig(): self
    {
        $config = config('eventapp.sms.cbmessaging');

        return new self((string) ($config['key'] ?? ''), (string) ($config['secret'] ?? ''), (string) ($config['sender_id'] ?? ''), (string) ($config['url'] ?? ''));
    }

    public function send(string $phone, string $message): SmsResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withBasicAuth($this->key, $this->secret)
                ->acceptJson()
                ->timeout(10)
                ->post('sms/send-message', ['to' => ltrim($phone, '+'), 'msg' => $message, 'senderID' => $this->senderId]);
        } catch (ConnectionException $e) {
            throw new SmsDeliveryFailed('CB Messaging unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new SmsDeliveryFailed("CB Messaging rejected the message ({$response->status()}).");
        }

        $id = $response->json('message_id') ?? $response->json('data.message_id') ?? $response->json('id') ?? $response->json('data.id');

        return new SmsResult(is_scalar($id) ? (string) $id : null);
    }
}

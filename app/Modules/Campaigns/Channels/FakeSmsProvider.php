<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Data\SmsResult;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;

/** Tests: records every SMS; numbers in $failing are refused. */
class FakeSmsProvider implements SmsProvider
{
    use ReadsSmsWebhooks;

    /** @var list<array{phone: string, message: string, id: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $failing = [];

    public function send(string $phone, string $message): SmsResult
    {
        if (in_array($phone, $this->failing, true)) {
            throw new SmsDeliveryFailed('fake refusal');
        }
        $id = 'sms-'.(count($this->sent) + 1);
        $this->sent[] = ['phone' => $phone, 'message' => $message, 'id' => $id];

        return new SmsResult($id);
    }
}

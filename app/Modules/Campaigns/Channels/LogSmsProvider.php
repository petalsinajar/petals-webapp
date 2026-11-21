<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Data\SmsResult;
use App\Modules\Identity\Services\LogSmsSender;
use Illuminate\Support\Str;

/** Local development: logs campaign SMS through the existing LogSmsSender. */
class LogSmsProvider implements SmsProvider
{
    use ReadsSmsWebhooks;

    public function __construct(private readonly LogSmsSender $log = new LogSmsSender) {}

    public function send(string $phone, string $message): SmsResult
    {
        $this->log->send($phone, $message);

        return new SmsResult('log-'.Str::ulid());
    }
}

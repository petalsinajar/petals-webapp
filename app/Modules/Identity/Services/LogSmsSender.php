<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development SMS driver: writes messages to the log instead of sending them.
 * Add a real driver (e.g. Hubtel, Arkesel, Twilio) by implementing SmsSender.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS', ['to' => $phone, 'message' => $message]);
    }
}

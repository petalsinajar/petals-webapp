<?php

namespace App\Modules\Campaigns\Data;

final class SmsResult
{
    public function __construct(public readonly ?string $messageId) {}
}

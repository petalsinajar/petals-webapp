<?php

namespace App\Modules\Campaigns\Data;

final class SmsDeliveryReport
{
    public function __construct(
        public readonly string $messageId,
        public readonly bool $delivered,
        public readonly ?string $status = null,
    ) {}
}

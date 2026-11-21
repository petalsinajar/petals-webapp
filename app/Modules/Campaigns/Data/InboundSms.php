<?php

namespace App\Modules\Campaigns\Data;

final class InboundSms
{
    /** [from] is E.164. */
    public function __construct(public readonly string $from, public readonly string $text) {}
}

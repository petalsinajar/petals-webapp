<?php

namespace App\Modules\Campaigns\Enums;

enum Channel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case InApp = 'in_app';

    public function label(): string
    {
        return config("eventapp.campaigns.channels.{$this->value}", $this->value);
    }
}

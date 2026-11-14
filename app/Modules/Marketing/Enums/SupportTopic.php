<?php

namespace App\Modules\Marketing\Enums;

enum SupportTopic: string
{
    case General = 'general';
    case Booking = 'booking';
    case Payment = 'payment';
    case Vendor = 'vendor';
    case Report = 'report';
    case Privacy = 'privacy';
    case AccountDeletion = 'account_deletion';

    public function label(): string
    {
        return match ($this) {
            self::General => 'A general question',
            self::Booking => 'A booking',
            self::Payment => 'A payment or refund',
            self::Vendor => 'Selling on Petals in a Jar',
            self::Report => 'Reporting a person, post or listing',
            self::Privacy => 'My data (see or correct it)',
            self::AccountDeletion => 'Delete my account',
        };
    }
}

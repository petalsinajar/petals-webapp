<?php

namespace App\Support;

/**
 * Normalises phone numbers to E.164. Local numbers default to Ghana (+233).
 * Swap for libphonenumber when you support more countries.
 */
final class PhoneNumber
{
    public static function normalize(string $input, string $defaultCountryCode = '233'): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '+'.$defaultCountryCode.substr($digits, 1);
        }

        if (! str_starts_with($digits, '+')) {
            $digits = '+'.$digits;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }
}

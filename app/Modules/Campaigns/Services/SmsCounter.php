<?php

namespace App\Modules\Campaigns\Services;

/**
 * SMS length and cost. GSM-7 messages fit 160 characters in one segment and 153 per
 * segment when split; anything outside GSM-7 (emoji, curly quotes, accents beyond the
 * basic set) switches the whole message to UCS-2: 70, then 67 per segment. Characters
 * in the GSM extension table (€ [ ] { } \ ~ ^ |) take two places.
 */
final class SmsCounter
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /** @return array{encoding: 'gsm7'|'ucs2', units: int, segments: int, per_segment: int} */
    public static function count(string $text): array
    {
        $chars = mb_str_split($text);
        $units = 0;
        $gsm = true;
        foreach ($chars as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $units += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if (! $gsm) {
            // UTF-16 code units: emoji outside the BMP take two.
            $units = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
            [$single, $multi] = [70, 67];
        } else {
            [$single, $multi] = [160, 153];
        }

        $segments = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));

        return [
            'encoding' => $gsm ? 'gsm7' : 'ucs2',
            'units' => $units,
            'segments' => $segments,
            'per_segment' => $segments > 1 ? $multi : $single,
        ];
    }

    public static function costMinor(int $segments, int $recipients = 1): int
    {
        return $segments * $recipients * (int) config('eventapp.campaigns.sms.cost_per_segment_minor');
    }
}

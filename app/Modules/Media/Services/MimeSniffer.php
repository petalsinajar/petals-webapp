<?php

namespace App\Modules\Media\Services;

/**
 * What a file really is, from its first bytes (magic numbers), whatever it was uploaded as.
 * Covers exactly the types uploads accept.
 */
final class MimeSniffer
{
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    private const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4A ', 'dash', 'MSNV', '3gp4', '3gp5'];

    public static function detect(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            substr($head, 4, 4) === 'ftyp' => self::isoBrand(substr($head, 8, 4)),
            default => null,
        };
    }

    private static function isoBrand(string $brand): ?string
    {
        return match (true) {
            in_array($brand, self::HEIC_BRANDS, true) => 'image/heic',
            $brand === 'qt  ' => 'video/quicktime',
            in_array($brand, self::MP4_BRANDS, true) => 'video/mp4',
            default => null,
        };
    }
}

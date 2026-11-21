<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Identity\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * A one-off list: a CSV of user ids or phone numbers (any column, header optional), matched
 * to existing users. Anything that isn't an existing account is dropped.
 */
final class AudienceCsv
{
    /** @return array{user_ids: list<string>, rows: int, unmatched: int} */
    public static function match(string $contents): array
    {
        $ids = [];
        $phones = [];
        $rows = 0;
        foreach (preg_split('/\r\n|\r|\n/', trim($contents)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows++;
            foreach (str_getcsv($line) as $cell) {
                $cell = trim($cell);
                if (Str::isUlid($cell)) {
                    $ids[] = strtolower($cell); // as HasUlids stores them
                } elseif (preg_match('/\d{6,}/', $cell) && ($phone = PhoneNumber::normalize($cell))) {
                    $phones[] = $phone;
                }
            }
        }

        $matched = User::query()
            ->where(fn ($q) => $q->whereIn('id', array_unique($ids))->orWhereIn('phone', array_unique($phones)))
            ->pluck('id')->unique()->values()->all();

        return ['user_ids' => $matched, 'rows' => $rows, 'unmatched' => max(0, $rows - count($matched))];
    }
}

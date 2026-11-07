<?php

namespace App\Support;

/**
 * Helpers for integer money in minor units. Never use floats for amounts.
 */
final class Money
{
    /** Percentage of an amount, rounded half up to the nearest minor unit. */
    public static function percent(int $amountMinor, float $percent): int
    {
        return (int) round($amountMinor * $percent / 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Split an amount across weights so the parts always add up to the total.
     *
     * @param  array<array-key, int>  $weights
     * @return array<array-key, int>
     */
    public static function allocate(int $amountMinor, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($amountMinor * $weight, $total);
            $allocated += $parts[$key];
        }

        // Give the leftover minor units to the largest weights first.
        $remainder = $amountMinor - $allocated;
        arsort($weights);
        foreach (array_keys($weights) as $key) {
            if ($remainder <= 0) {
                break;
            }
            $parts[$key]++;
            $remainder--;
        }

        return $parts;
    }
}

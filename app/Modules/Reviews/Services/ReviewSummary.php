<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Reviews\Models\Review;
use Illuminate\Database\Eloquent\Builder;

/** Average, count, per-star counts and aspect averages over a set of published reviews. */
final class ReviewSummary
{
    /**
     * @param  Builder<Review>  $reviews  already narrowed to the published reviews to summarise
     * @return array{average: float, count: int, stars: array<string, int>, aspects: array<string, float|null>}
     */
    public static function of(Builder $reviews, array $aspectKeys): array
    {
        $stars = (clone $reviews)->toBase()
            ->selectRaw('rating, COUNT(*) AS n')->groupBy('rating')
            ->pluck('n', 'rating');

        $count = (int) $stars->sum();
        $sum = $stars->reduce(fn (int $carry, $n, $rating) => $carry + (int) $rating * (int) $n, 0);

        // Aspects are JSON; summed here rather than with driver-specific JSON SQL.
        $totals = array_fill_keys($aspectKeys, [0, 0]);
        foreach ((clone $reviews)->whereNotNull('aspects')->select('id', 'aspects')->lazyById(500) as $review) {
            foreach (array_intersect_key($review->aspects ?? [], $totals) as $key => $value) {
                $totals[$key][0] += (int) $value;
                $totals[$key][1]++;
            }
        }

        return [
            'average' => $count ? round($sum / $count, 2) : 0.0,
            'count' => $count,
            'stars' => collect(range(5, 1))->mapWithKeys(fn (int $s) => [(string) $s => (int) ($stars[$s] ?? 0)])->all(),
            'aspects' => array_map(fn (array $t) => $t[1] ? round($t[0] / $t[1], 2) : null, $totals),
        ];
    }
}

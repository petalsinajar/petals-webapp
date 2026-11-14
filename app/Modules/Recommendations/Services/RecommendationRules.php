<?php

namespace App\Modules\Recommendations\Services;

use App\Modules\Events\Models\EventType;
use App\Modules\Recommendations\Enums\RecommendationReason;
use Carbon\CarbonImmutable;

/**
 * The timing and scoring rules, as plain functions of the config (eventapp.recommendations).
 * No database access, so they're easy to test and reason about.
 */
final class RecommendationRules
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        $config = config('eventapp.recommendations');
        // Set per event type in the admin panel; config is the fallback.
        $config['required_categories'] = [
            ...$config['required_categories'],
            ...EventType::requiredCategories(),
        ];

        return new self($config);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Which stage an event is in right now, or null when nothing should be sent.
     * A last call wins over a fresh checklist, which wins over the general reminder.
     */
    public function stage(CarbonImmutable $startsAt, CarbonImmutable $createdAt, CarbonImmutable $now): ?RecommendationReason
    {
        if ($startsAt->lessThanOrEqualTo($now)) {
            return null;
        }

        $daysLeft = $now->diffInDays($startsAt);
        $within = fn (string $stage) => $daysLeft <= $this->config("stages.$stage.from_days")
            && $daysLeft >= $this->config("stages.$stage.to_days");

        return match (true) {
            $within('last_call') => RecommendationReason::LastCall,
            $createdAt->diffInHours($now) <= $this->config('stages.checklist.created_within_hours') => RecommendationReason::Checklist,
            $within('missing_category') => RecommendationReason::MissingCategory,
            default => null,
        };
    }

    /** Inside the no-send window in this timezone? The window may wrap midnight (21:00–08:00). */
    public function isQuiet(CarbonImmutable $now, string $timezone): bool
    {
        $time = $now->setTimezone($timezone)->format('H:i');
        $start = $this->config('quiet_hours.start');
        $end = $this->config('quiet_hours.end');

        return $start <= $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    /** Now, or the end of the current quiet window. */
    public function nextSendAt(CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        if (! $this->isQuiet($now, $timezone)) {
            return $now;
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->config('quiet_hours.end')));
        $local = $now->setTimezone($timezone);
        $end = $local->setTime($hour, $minute);

        return ($end->lessThanOrEqualTo($local) ? $end->addDay() : $end)->setTimezone($now->getTimezone());
    }

    /** Rough quantity needed: per guest for listed categories (chairs, tables), otherwise one. */
    public function estimateQty(string $category, ?int $guestCount): int
    {
        $perGuest = $this->config("units_per_guest.$category");

        return $perGuest !== null && $guestCount
            ? max(1, (int) ceil($guestCount * $perGuest))
            : 1;
    }

    public function fitsBudget(int $estimateMinor, ?int $budgetMinor): bool
    {
        return ! $budgetMinor || $estimateMinor <= $budgetMinor * $this->config('max_budget_share');
    }

    // ---- Score components, each 0..1 --------------------------------------

    /** New vendors without reviews score as average, so they aren't buried. */
    public function ratingScore(float $avg, int $count): float
    {
        return $count > 0 ? $this->clamp($avg / 5) : 0.5;
    }

    /** Completed bookings, with diminishing returns (50 bookings ≈ full marks). */
    public function experienceScore(int $completedBookings): float
    {
        return $this->clamp(log(1 + $completedBookings) / log(51));
    }

    /** Closer is better; unknown distance is neutral. */
    public function distanceScore(?float $km, int $radiusKm): float
    {
        return $km === null || $radiusKm <= 0 ? 0.5 : $this->clamp(1 - $km / $radiusKm);
    }

    /** Cheaper relative to the budget is better; no budget is neutral. */
    public function priceScore(int $estimateMinor, ?int $budgetMinor): float
    {
        return $budgetMinor
            ? $this->clamp(1 - $estimateMinor / ($budgetMinor * $this->config('max_budget_share')))
            : 0.5;
    }

    /** Fewer recent recommendations means a bigger boost, so the same few vendors don't get everything. */
    public function fairnessScore(int $recentRecommendations): float
    {
        return $this->clamp(1 - $recentRecommendations / max(1, $this->config('fairness_cap')));
    }

    /** A paid plan's visibility boost, as a share of the largest boost there is. */
    public function planScore(float $boost): float
    {
        $max = (float) config('eventapp.visibility.max_boost');

        return $max > 0 ? $this->clamp($boost / $max) : 0.0;
    }

    /** @param  array<string, float>  $components  keyed like the weights config */
    public function score(array $components): float
    {
        $score = 0.0;
        foreach ($this->config('weights') as $key => $weight) {
            $score += $weight * ($components[$key] ?? 0);
        }

        return round($score, 4);
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}

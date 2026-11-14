<?php

namespace App\Filament\Admin\Widgets;

use App\Modules\Subscriptions\Services\SubscriptionMetrics;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** MRR, vendors per plan and this month's churn. */
class SubscriptionRevenue extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $metrics = app(SubscriptionMetrics::class);
        $churn = $metrics->churnThisMonth();
        $byPlan = collect($metrics->activeByPlan())->map(fn (int $n, string $plan) => ucfirst($plan).": {$n}")->implode(' · ');

        return [
            Stat::make('MRR', Money::format($metrics->mrrMinor(), config('eventapp.currency')))->description('Paying plans; yearly counted monthly'),
            Stat::make('Vendors by plan', array_sum($metrics->activeByPlan()))->description($byPlan),
            Stat::make('Churn this month', $churn['churned'])->description(round($churn['rate'] * 100, 1).'% of paying vendors'),
        ];
    }
}

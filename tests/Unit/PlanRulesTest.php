<?php

namespace Tests\Unit;

use App\Modules\Recommendations\Services\RecommendationRules;
use App\Modules\Subscriptions\Enums\BillingInterval;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Services\Entitlements;
use App\Modules\Subscriptions\Services\VendorAnalytics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PlanRulesTest extends TestCase
{
    public function test_visibility_boosts_come_from_config_and_are_capped(): void
    {
        $this->assertSame(0.06, (new Plan(['visibility_level' => 'priority']))->visibilityBoost());
        $this->assertSame(0.0, (new Plan(['visibility_level' => 'unknown']))->visibilityBoost());

        config(['eventapp.visibility.boost.top' => 0.5]);
        $this->assertSame(0.1, (new Plan(['visibility_level' => 'top']))->visibilityBoost(), 'never above max_boost');
    }

    public function test_the_plan_is_a_small_part_of_a_recommendation_score(): void
    {
        $rules = new RecommendationRules(config('eventapp.recommendations'));
        $this->assertSame(1.0, $rules->planScore(0.1));
        $this->assertSame(0.0, $rules->planScore(0.0));

        $same = ['rating' => 0.8, 'experience' => 0.5, 'distance' => 0.7, 'price' => 0.5, 'fairness' => 0.5];
        // A clearly better match (nearer and better rated) still beats a top plan.
        $better = ['rating' => 1.0, 'distance' => 0.9] + $same;
        $this->assertGreaterThan($rules->score([...$same, 'plan' => 1.0]), $rules->score([...$better, 'plan' => 0.0]));
        $this->assertSame(0.05, round($rules->score([...$same, 'plan' => 1.0]) - $rules->score([...$same, 'plan' => 0.0]), 4));
    }

    public function test_analytics_levels_include_the_ones_below(): void
    {
        $analytics = new VendorAnalytics(new Entitlements);

        $this->assertSame(['bookings', 'completed_bookings', 'rating'], $analytics->allowedMetrics('basic'));
        $this->assertContains('rating', $analytics->allowedMetrics('full_plus'));
        $this->assertContains('repeat_customers', $analytics->allowedMetrics('full_plus'));
        $this->assertNotContains('shop_taps', $analytics->allowedMetrics('standard'));
    }

    public function test_billing_intervals_add_without_overflowing_month_ends(): void
    {
        $this->assertSame('2027-02-28', BillingInterval::Month->addTo(CarbonImmutable::parse('2027-01-31'))->toDateString());
        $this->assertSame('2029-02-28', BillingInterval::Year->addTo(CarbonImmutable::parse('2028-02-29'))->toDateString());
    }
}

<?php

namespace Tests\Unit;

use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Modules\Recommendations\Services\RecommendationRules;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RecommendationRulesTest extends TestCase
{
    private RecommendationRules $rules;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new RecommendationRules([
            'stages' => [
                'checklist' => ['created_within_hours' => 48],
                'missing_category' => ['from_days' => 56, 'to_days' => 14],
                'last_call' => ['from_days' => 11, 'to_days' => 7],
            ],
            'quiet_hours' => ['start' => '21:00', 'end' => '08:00'],
            'units_per_guest' => ['chairs' => 1, 'tables' => 0.1],
            'max_budget_share' => 0.4,
            'fairness_cap' => 20,
            'weights' => ['rating' => 0.25, 'experience' => 0.15, 'distance' => 0.2, 'price' => 0.15, 'fairness' => 0.25],
        ]);
        $this->now = CarbonImmutable::parse('2026-11-10 10:00', 'UTC');
    }

    private function stage(int $daysUntil, int $createdHoursAgo = 24 * 30): ?RecommendationReason
    {
        return $this->rules->stage($this->now->addDays($daysUntil), $this->now->subHours($createdHoursAgo), $this->now);
    }

    public function test_stages_follow_the_planning_timeline(): void
    {
        $this->assertNull($this->stage(90));                                        // too early
        $this->assertSame(RecommendationReason::MissingCategory, $this->stage(56));
        $this->assertSame(RecommendationReason::MissingCategory, $this->stage(14));
        $this->assertNull($this->stage(12));                                        // between the two windows
        $this->assertSame(RecommendationReason::LastCall, $this->stage(10));
        $this->assertNull($this->stage(5));                                         // too late to be useful
        $this->assertNull($this->stage(0));
        $this->assertNull($this->stage(-3));                                        // nothing after the event
    }

    public function test_a_new_event_gets_its_checklist_but_last_call_wins(): void
    {
        $this->assertSame(RecommendationReason::Checklist, $this->stage(90, createdHoursAgo: 2));
        $this->assertSame(RecommendationReason::Checklist, $this->stage(30, createdHoursAgo: 47));
        $this->assertSame(RecommendationReason::LastCall, $this->stage(9, createdHoursAgo: 2));
        $this->assertNull($this->stage(-1, createdHoursAgo: 2));
    }

    public function test_quiet_hours_wrap_midnight_in_the_events_timezone(): void
    {
        $at = fn (string $time, string $tz = 'Africa/Accra') => CarbonImmutable::parse("2026-11-10 $time", $tz);

        $this->assertFalse($this->rules->isQuiet($at('08:00'), 'Africa/Accra'));
        $this->assertFalse($this->rules->isQuiet($at('20:59'), 'Africa/Accra'));
        $this->assertTrue($this->rules->isQuiet($at('21:00'), 'Africa/Accra'));
        $this->assertTrue($this->rules->isQuiet($at('03:00'), 'Africa/Accra'));
        $this->assertTrue($this->rules->isQuiet($at('07:59'), 'Africa/Accra'));

        // 20:30 UTC is 21:30 in Lagos: quiet there, not in Accra.
        $this->assertTrue($this->rules->isQuiet($at('20:30', 'UTC'), 'Africa/Lagos'));
        $this->assertFalse($this->rules->isQuiet($at('20:30', 'UTC'), 'Africa/Accra'));
    }

    public function test_next_send_is_the_end_of_the_quiet_window(): void
    {
        $evening = CarbonImmutable::parse('2026-11-10 22:15', 'Africa/Accra');
        $earlyMorning = CarbonImmutable::parse('2026-11-11 03:00', 'Africa/Accra');
        $daytime = CarbonImmutable::parse('2026-11-10 12:00', 'Africa/Accra');

        $this->assertSame('2026-11-11 08:00', $this->rules->nextSendAt($evening, 'Africa/Accra')->setTimezone('Africa/Accra')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 08:00', $this->rules->nextSendAt($earlyMorning, 'Africa/Accra')->setTimezone('Africa/Accra')->format('Y-m-d H:i'));
        $this->assertTrue($this->rules->nextSendAt($daytime, 'Africa/Accra')->equalTo($daytime));
    }

    public function test_quantity_and_budget_estimates(): void
    {
        $this->assertSame(150, $this->rules->estimateQty('chairs', 150));
        $this->assertSame(15, $this->rules->estimateQty('tables', 150));
        $this->assertSame(1, $this->rules->estimateQty('sound', 150));
        $this->assertSame(1, $this->rules->estimateQty('chairs', null));

        $this->assertTrue($this->rules->fitsBudget(400000, 1000000));   // 40% of the budget
        $this->assertFalse($this->rules->fitsBudget(400001, 1000000));
        $this->assertTrue($this->rules->fitsBudget(99999999, null));     // no budget set
    }

    public function test_score_components_and_weights(): void
    {
        $this->assertSame(0.5, $this->rules->ratingScore(0, 0));         // new vendors aren't buried
        $this->assertSame(0.9, $this->rules->ratingScore(4.5, 12));
        $this->assertSame(0.0, $this->rules->experienceScore(0));
        $this->assertEqualsWithDelta(1.0, $this->rules->experienceScore(50), 0.0001);
        $this->assertSame(0.75, $this->rules->distanceScore(10, 40));
        $this->assertSame(0.5, $this->rules->distanceScore(null, 40));
        $this->assertSame(0.5, $this->rules->priceScore(1000, null));
        $this->assertSame(1.0, $this->rules->fairnessScore(0));
        $this->assertSame(0.0, $this->rules->fairnessScore(25));

        $this->assertSame(1.0, $this->rules->score(['rating' => 1, 'experience' => 1, 'distance' => 1, 'price' => 1, 'fairness' => 1]));
        $this->assertSame(0.25, $this->rules->score(['fairness' => 1]));
    }
}

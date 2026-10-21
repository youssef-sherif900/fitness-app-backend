<?php

namespace Tests\Unit;

use App\Services\Nutrition\AdaptiveTargetService;
use App\Services\Nutrition\TargetCalculator;
use App\Services\Nutrition\TrendService;
use PHPUnit\Framework\TestCase;

class TrendAndAdaptiveTest extends TestCase
{
    public function test_trend_smooths_and_handles_gaps(): void
    {
        $s = (new TrendService(0.1))->compute([
            ['date' => '2026-10-01', 'weight' => 80.0], ['date' => '2026-10-02', 'weight' => 80.5],
            ['date' => '2026-10-05', 'weight' => 79.0], ['date' => '2026-10-15', 'weight' => 78.0],
        ]);

        $this->assertEqualsWithDelta([80.0, 80.05, 79.77, 78.62], array_column($s, 'trend'), 0.005);
    }

    public function test_latest_weight_of_a_day_wins_and_input_order_does_not_matter_for_dates(): void
    {
        $s = (new TrendService(0.1))->compute([
            ['date' => '2026-10-02', 'weight' => 79.0], ['date' => '2026-10-01', 'weight' => 80.0], ['date' => '2026-10-01', 'weight' => 81.0],
        ]);

        $this->assertSame('2026-10-01', $s[0]['date']);
        $this->assertSame(81.0, $s[0]['weight']);
    }

    public function test_weekly_slope_sign(): void
    {
        $t = new TrendService(0.5);
        $s = $t->compute([['date' => '2026-10-01', 'weight' => 80.0], ['date' => '2026-10-08', 'weight' => 79.0]]);
        $this->assertLessThan(0, $t->weeklySlope($s));
        $this->assertNull($t->weeklySlope([$s[0]]));
    }

    private function adaptive(): AdaptiveTargetService
    {
        $calc = new TargetCalculator(['light' => 1.375], 7700, 1200, 1.0, 1.8, 0.25);

        return new AdaptiveTargetService($calc, ['window_days' => 14, 'min_weigh_ins' => 4, 'min_adherence' => 0.6, 'damping' => 0.5, 'max_step_kcal' => 150], 7700);
    }

    public function test_holds_with_too_few_weigh_ins_or_low_adherence(): void
    {
        $this->assertSame(0, $this->adaptive()->propose(2200, -0.5, -0.1, 2, 0.9)['change_kcal']);
        $this->assertSame(0, $this->adaptive()->propose(2200, -0.5, -0.1, 8, 0.3)['change_kcal']);
    }

    public function test_lowers_target_when_loss_is_too_slow_and_caps_the_step(): void
    {
        $r = $this->adaptive()->propose(2200, -0.5, -0.1, 6, 0.9);

        $this->assertTrue($r['applied']);
        $this->assertSame(-150, $r['change_kcal']);
        $this->assertSame(2050, $r['new_kcal']);
        $this->assertStringContainsString('lowered', $r['reason']);
    }

    public function test_raises_target_when_losing_too_fast(): void
    {
        $r = $this->adaptive()->propose(2000, -0.5, -0.9, 6, 0.9);
        $this->assertGreaterThan(0, $r['change_kcal']);
    }

    public function test_never_goes_below_minimum_and_holds_when_on_track(): void
    {
        $floor = $this->adaptive()->propose(1210, -0.5, 0.2, 6, 0.9);
        $this->assertGreaterThanOrEqual(1200, $floor['new_kcal']);
        $this->assertFalse($floor['applied']);

        $this->assertFalse($this->adaptive()->propose(2200, -0.5, -0.5, 6, 0.9)['applied']);
    }
}

<?php

namespace Tests\Unit;

use App\Exceptions\GuardrailViolation;
use App\Services\Nutrition\TargetCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TargetCalculatorTest extends TestCase
{
    private function calc(int $minKcal = 1200, float $maxLossPct = 1.0): TargetCalculator
    {
        return new TargetCalculator(
            ['sedentary' => 1.2, 'light' => 1.375, 'moderate' => 1.55, 'active' => 1.725, 'very_active' => 1.9],
            7700, $minKcal, $maxLossPct, 1.8, 0.25,
        );
    }

    /** Critical test case 1: golden values for sample profiles (hand-calculated Mifflin-St Jeor). */
    public static function golden(): array
    {
        return [
            // BMR 1780, TDEE 2759, deficit 550 -> 2209; protein 144 g; fat 25% -> 61 g; carbs remainder -> 271 g
            'male 30y 80kg 180cm moderate, lose 0.5/wk' => [['male', 80, 180, 30, 'moderate', 'lose', 0.5], 1780, 2759, 2209, 144, 271, 61],
            // BMR 1345.25, TDEE 1849.7 -> 1850
            'female 25y 60kg 165cm light, maintain' => [['female', 60, 165, 25, 'light', 'maintain', 0.0], 1345, 1850, 1850, 108, 240, 51],
            // gain: +0.25 kg/wk = +275 kcal/day on TDEE 2759 -> 3034
            'male 30y 80kg 180cm moderate, gain 0.25/wk' => [['male', 80, 180, 30, 'moderate', 'gain', 0.25], 1780, 2759, 3034, 144, 426, 84],
        ];
    }

    #[DataProvider('golden')]
    public function test_golden_values(array $in, int $bmr, int $tdee, int $kcal, int $protein, int $carbs, int $fat): void
    {
        $r = $this->calc()->calculate(...$in);

        $this->assertSame($bmr, $r['bmr']);
        $this->assertSame($tdee, $r['tdee']);
        $this->assertSame($kcal, $r['kcal']);
        $this->assertSame($protein, $r['protein_g']);
        $this->assertSame($carbs, $r['carbs_g']);
        $this->assertSame($fat, $r['fat_g']);
    }

    public function test_rejects_target_below_minimum(): void
    {
        // BMR 1139, TDEE 1366.8, deficit 275 at 0.5 kg/wk -> 1092 < 1200
        try {
            $this->calc()->calculate('female', 50, 160, 40, 'sedentary', 'lose', 0.5);
            $this->fail('Expected a guardrail violation');
        } catch (GuardrailViolation $e) {
            $this->assertSame('target_below_minimum', $e->errorCode);
        }
    }

    public function test_rejects_loss_rate_above_cap(): void
    {
        $this->expectException(GuardrailViolation::class);
        // 1.0 kg/wk on 80 kg is 1.25% of body weight, over the 1.0% cap
        $this->calc()->calculate('male', 80, 180, 30, 'moderate', 'lose', 1.0);
    }

    public function test_loss_rate_exactly_at_cap_is_allowed(): void
    {
        $r = $this->calc()->calculate('male', 100, 185, 30, 'active', 'lose', 1.0); // 1.0% of 100 kg
        $this->assertGreaterThanOrEqual(1200, $r['kcal']);
    }

    public function test_maintain_ignores_rate(): void
    {
        $a = $this->calc()->calculate('male', 80, 180, 30, 'moderate', 'maintain', 5.0);
        $this->assertSame(2759, $a['kcal']);
    }

    public function test_minimum_is_configurable(): void
    {
        $this->expectException(GuardrailViolation::class);
        $this->calc(minKcal: 3000)->calculate('male', 80, 180, 30, 'moderate', 'maintain');
    }
}

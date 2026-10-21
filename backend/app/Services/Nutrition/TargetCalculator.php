<?php

namespace App\Services\Nutrition;

use App\Exceptions\GuardrailViolation;
use InvalidArgumentException;

/**
 * PROF-2 / PROF-3: daily calorie + macro targets.
 *
 *   BMR  = 10*kg + 6.25*cm - 5*age + (male ? 5 : -161)      (Mifflin-St Jeor)
 *   TDEE = BMR * activity factor
 *   goal lose:     target = TDEE - rate*kcal_per_kg/7
 *   goal gain:     target = TDEE + rate*kcal_per_kg/7
 *   goal maintain: target = TDEE
 *
 * Pure class (no framework dependencies) so golden-value tests are fast and exact.
 */
class TargetCalculator
{
    public function __construct(
        private readonly array $activityFactors,
        private readonly int $kcalPerKg,
        private readonly int $minDailyKcal,
        private readonly float $maxWeeklyLossPct,
        private readonly float $proteinGPerKg,
        private readonly float $fatPct,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('fitness.activity_factors'),
            config('fitness.kcal_per_kg'),
            config('fitness.guardrails.min_daily_kcal'),
            config('fitness.guardrails.max_weekly_loss_pct'),
            config('fitness.macros.protein_g_per_kg'),
            config('fitness.macros.fat_pct'),
        );
    }

    public function bmr(string $sex, float $weightKg, float $heightCm, int $age): float
    {
        return 10 * $weightKg + 6.25 * $heightCm - 5 * $age + ($sex === 'male' ? 5 : -161);
    }

    public function tdee(float $bmr, string $activity): float
    {
        return $bmr * ($this->activityFactors[$activity]
            ?? throw new InvalidArgumentException("Unknown activity level: {$activity}"));
    }

    /**
     * @param  string  $goal  lose|maintain|gain
     * @param  float  $weeklyRateKg  positive kg per week (ignored for maintain)
     * @return array{bmr:int,tdee:int,kcal:int,protein_g:int,carbs_g:int,fat_g:int}
     *
     * @throws GuardrailViolation
     */
    public function calculate(
        string $sex, float $weightKg, float $heightCm, int $age,
        string $activity, string $goal, float $weeklyRateKg = 0.0,
    ): array {
        $rate = $goal === 'maintain' ? 0.0 : abs($weeklyRateKg);
        $this->assertWeeklyRate($goal, $rate, $weightKg);

        $bmr = $this->bmr($sex, $weightKg, $heightCm, $age);
        $tdee = $this->tdee($bmr, $activity);
        $dailyDelta = $rate * $this->kcalPerKg / 7;

        $kcal = (int) round(match ($goal) {
            'lose' => $tdee - $dailyDelta,
            'gain' => $tdee + $dailyDelta,
            default => $tdee,
        });

        $this->assertMinimumKcal($kcal);

        return ['bmr' => (int) round($bmr), 'tdee' => (int) round($tdee)] + $this->macros($kcal, $weightKg);
    }

    /** @return array{kcal:int,protein_g:int,carbs_g:int,fat_g:int} */
    public function macros(int $kcal, float $weightKg): array
    {
        $protein = (int) round($this->proteinGPerKg * $weightKg);
        $fat = (int) round($kcal * $this->fatPct / 9);
        $carbs = max(0, (int) round(($kcal - $protein * 4 - $fat * 9) / 4));

        return ['kcal' => $kcal, 'protein_g' => $protein, 'carbs_g' => $carbs, 'fat_g' => $fat];
    }

    public function assertWeeklyRate(string $goal, float $rateKg, float $weightKg): void
    {
        if ($goal !== 'lose') {
            return;
        }
        $cap = $weightKg * $this->maxWeeklyLossPct / 100;
        if ($rateKg > $cap + 1e-9) {
            throw new GuardrailViolation(
                'weekly_loss_rate_too_high',
                'The requested weekly loss rate exceeds the safe maximum.',
                ['requested_kg_per_week' => $rateKg, 'max_kg_per_week' => round($cap, 2)],
            );
        }
    }

    public function assertMinimumKcal(int $kcal): void
    {
        if ($kcal < $this->minDailyKcal) {
            throw new GuardrailViolation(
                'target_below_minimum',
                'The resulting daily calorie target is below the safe minimum.',
                ['target_kcal' => $kcal, 'min_kcal' => $this->minDailyKcal],
            );
        }
    }

    public function minDailyKcal(): int
    {
        return $this->minDailyKcal;
    }
}

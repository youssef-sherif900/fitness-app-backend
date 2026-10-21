<?php

namespace App\Services\Nutrition;

/**
 * NUT-1: weekly adaptive adjustment from weight trend and adherence, always with a visible reason.
 * Pure decision logic; callers load the data and decide whether to apply or send to a trainer.
 */
class AdaptiveTargetService
{
    public function __construct(
        private readonly TargetCalculator $calculator,
        private readonly array $cfg,         // config('fitness.adaptive')
        private readonly int $kcalPerKg,
    ) {}

    /**
     * @param  float  $expectedWeeklyKg  signed: -0.5 means "losing 0.5 kg/week", +0.25 gain, 0 maintain
     * @param  float|null  $actualWeeklyKg  trend slope over the window
     * @return array{change_kcal:int,new_kcal:int,reason:string,applied:bool}
     */
    public function propose(
        int $currentKcal, float $expectedWeeklyKg, ?float $actualWeeklyKg,
        int $weighIns, float $adherence,
    ): array {
        $hold = fn (string $why) => ['change_kcal' => 0, 'new_kcal' => $currentKcal, 'reason' => $why, 'applied' => false];

        if ($weighIns < $this->cfg['min_weigh_ins'] || $actualWeeklyKg === null) {
            return $hold('Not enough weigh-ins this period to adjust your target. Weigh in a few more times.');
        }
        if ($adherence < $this->cfg['min_adherence']) {
            return $hold('Food logging was too sparse to judge your progress, so your target stays the same.');
        }

        // If the trend moves slower (or the wrong way) vs the plan, `error` has the sign of "eat less".
        $errorKgPerWeek = $actualWeeklyKg - $expectedWeeklyKg;
        $raw = -$errorKgPerWeek * $this->kcalPerKg / 7 * $this->cfg['damping'];
        $step = (int) round(max(-$this->cfg['max_step_kcal'], min($this->cfg['max_step_kcal'], $raw)) / 5) * 5;

        $floor = $this->calculator->minDailyKcal();
        $new = max($floor, $currentKcal + $step);
        if ($step < 0 && $new - $currentKcal > -25) {
            return $hold('Your target is already at the safe minimum, so it cannot go lower. Review your goal or activity with your trainer.');
        }
        $step = $new - $currentKcal;

        if (abs($step) < 25) {
            return $hold('Your trend is on track, so your target stays the same.');
        }

        $dir = $step < 0 ? 'lowered' : 'raised';

        return [
            'change_kcal' => $step,
            'new_kcal' => $new,
            'applied' => true,
            'reason' => sprintf(
                'Your weight trend moved %+.2f kg/week against a plan of %+.2f kg/week, so your target was %s by %d kcal.',
                $actualWeeklyKg, $expectedWeeklyKg, $dir, abs($step),
            ),
        ];
    }
}

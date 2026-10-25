<?php
namespace App\Services;

use App\Models\{Program, ProgramDay, ProgramItem, User};

class ProgressionService
{
    public function __construct(private PerformanceService $perf)
    {
    }

    public function forDay(Program $p, ProgramDay $day, User $u): array
    {
        return $day->items->map(fn($i) => $this->forItem($p, $day, $i, $u))->all();
    }

    public function forItem(Program $p, ProgramDay $day, ProgramItem $item, User $u): array
    {
        $rule = $item->progression_rule ?? ['type' => 'none'];
        $type = $rule['type'] ?? 'none';
        $last = $this->perf->last($u->id, $item->exercise_id);
        $sets = $last['sets'] ?? collect();

        $current = [
            'sets' => $item->sets,
            'rep_min' => $item->rep_min,
            'rep_max' => $item->rep_max,
            'weight_kg' => $item->weight_kg ?? ($sets->isNotEmpty() ? (float) $sets->max('weight_kg') : null),
        ];
        $proposed = $current;
        $reason = 'No progression rule configured.';
        $approval = false;

        if ($type !== 'none') {
            if ($sets->isEmpty() || $current['weight_kg'] === null) {
                $reason = 'No completed performance available yet.';
            } else {
                $thr = $rule['target_rpe'] ?? $item->target_rpe;
                $enough = $sets->count() >= $item->sets;
                $rpeOk = $thr === null || $sets->every(fn($s) => $s->rpe === null || $s->rpe <= $thr);
                $inc = (float) ($rule['increment'] ?? 2.5);

                if ($type === 'double_progression') {
                    // Criterion: ALL prescribed working sets reach rep_max at/below the RPE threshold.
                    if ($enough && $rpeOk && $sets->every(fn($s) => $s->reps >= $item->rep_max)) {
                        $proposed['weight_kg'] = round($current['weight_kg'] + $inc, 2);
                        $reason = 'All prescribed working sets reached the upper rep target within the configured RPE limit.';
                    } elseif ($sets->max('reps') < $item->rep_min) {
                        $reason = 'Minimum rep target was not reached; load is held for review.';
                        $approval = true;
                    } else {
                        $reason = 'Keep the current weight until all working sets reach the upper rep target.';
                    }
                } elseif ($type === 'fixed_increment') {
                    if ($enough && $rpeOk && $sets->every(fn($s) => $s->reps >= $item->rep_min)) {
                        $proposed['weight_kg'] = round($current['weight_kg'] + $inc, 2);
                        $reason = 'All working sets met the minimum reps within the configured RPE limit.';
                    } else {
                        $reason = 'Completion criteria for a fixed increment were not met.';
                    }
                } elseif ($type === 'deload') {
                    $pct = (float) ($rule['reduction_percent'] ?? 10);
                    $proposed['weight_kg'] = round($current['weight_kg'] * (1 - $pct / 100) / 0.5) * 0.5;
                    $proposed['sets'] = max(1, (int) ceil($item->sets * (float) ($rule['sets_multiplier'] ?? 1)));
                    $reason = "Configured deload: {$pct}% load reduction.";
                    $approval = true;
                }
            }
        }

        return [
            'program_id' => $p->id,
            'program_day_id' => $day->id,
            'exercise_id' => $item->exercise_id,
            'current_prescription' => $current,
            'proposed_prescription' => $proposed,
            'rule' => $type,
            'reason' => $reason,
            'requires_approval' => $approval,
        ];
    }
}
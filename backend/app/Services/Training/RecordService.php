<?php
namespace App\Services;

use App\Models\{Exercise, PersonalRecord, PrEvent, Workout, WorkoutSet};
use Illuminate\Database\Eloquent\Builder;

class RecordService
{
    private function eligible(string $userId, string $exerciseId): Builder
    {
        return WorkoutSet::query()
            ->join('workouts', 'workouts.id', '=', 'workout_sets.workout_id')
            ->where('workout_sets.user_id', $userId)
            ->where('workout_sets.exercise_id', $exerciseId)
            ->where('workout_sets.is_warmup', false)
            ->where('workouts.status', 'completed')
            ->select('workout_sets.*');
    }

    /** Recompute current best records for one exercise from source sets. */
    public function recalc(string $userId, string $exerciseId): void
    {
        $type = Exercise::whereKey($exerciseId)->value('exercise_type');
        $best = [];

        if ($type === 'weighted') {
            $s = $this->eligible($userId, $exerciseId)->whereNotNull('weight_kg')->where('weight_kg', '>', 0)->where('reps', '>=', 1)
                ->orderByDesc('weight_kg')->orderBy('workout_sets.completed_at')->orderBy('workout_sets.id')->first();
            if ($s)
                $best['max_weight'] = [$s, $s->weight_kg];

            $s = $this->eligible($userId, $exerciseId)->where('reps', '>=', 1)
                ->orderByDesc('reps')->orderByDesc('weight_kg')->orderBy('workout_sets.completed_at')->orderBy('workout_sets.id')->first();
            if ($s)
                $best['max_reps'] = [$s, $s->reps];

            $s = $this->eligible($userId, $exerciseId)->where('weight_kg', '>', 0)
                ->whereBetween('reps', [1, config('training.e1rm_max_reps')])
                ->orderByRaw('workout_sets.weight_kg * (1 + workout_sets.reps / 30.0) desc')
                ->orderBy('workout_sets.completed_at')->orderBy('workout_sets.id')->first();
            if ($s)
                $best['estimated_1rm'] = [$s, round($s->weight_kg * (1 + $s->reps / 30), 2)];
        } elseif ($type === 'bodyweight_reps') {
            $s = $this->eligible($userId, $exerciseId)->where('reps', '>=', 1)
                ->orderByDesc('reps')->orderBy('workout_sets.completed_at')->orderBy('workout_sets.id')->first();
            if ($s)
                $best['max_reps'] = [$s, $s->reps];
        }

        foreach (['max_weight', 'max_reps', 'estimated_1rm'] as $rt) {
            $key = ['user_id' => $userId, 'exercise_id' => $exerciseId, 'record_type' => $rt];
            if (isset($best[$rt])) {
                [$set, $val] = $best[$rt];
                PersonalRecord::updateOrCreate($key, [
                    'value' => $val,
                    'workout_id' => $set->workout_id,
                    'workout_set_id' => $set->id,
                    'achieved_at' => $set->completed_at,
                ]);
            } else {
                PersonalRecord::where($key)->delete();
            }
        }
    }

    /** Called inside the completion transaction. Idempotent via pr_events unique key. */
    public function processWorkout(Workout $w): void
    {
        $exerciseIds = WorkoutSet::where('workout_id', $w->id)->where('is_warmup', false)->distinct()->pluck('exercise_id');
        foreach ($exerciseIds as $exId) {
            $before = PersonalRecord::where('user_id', $w->user_id)->where('exercise_id', $exId)->pluck('value', 'record_type');
            $this->recalc($w->user_id, $exId);
            $after = PersonalRecord::where('user_id', $w->user_id)->where('exercise_id', $exId)->where('workout_id', $w->id)->get();
            foreach ($after as $rec) {
                $prev = $before[$rec->record_type] ?? null;
                if ($prev === null || $rec->value > (float) $prev) {
                    PrEvent::firstOrCreate(
                        ['workout_id' => $w->id, 'exercise_id' => $exId, 'record_type' => $rec->record_type],
                        ['user_id' => $w->user_id, 'previous_value' => $prev, 'new_value' => $rec->value, 'achieved_at' => $rec->achieved_at]
                    );
                }
            }
        }
    }
}
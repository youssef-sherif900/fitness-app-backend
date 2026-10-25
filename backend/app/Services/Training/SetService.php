<?php
namespace App\Services;

use App\Models\{Exercise, Workout, WorkoutExercise, WorkoutSet};
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class SetService
{
    public function validateForType(Exercise $ex, array $d): void
    {
        $need = match ($ex->exercise_type) {
            'weighted', 'assisted' => ['weight_kg', 'reps'],
            'bodyweight', 'bodyweight_reps' => ['reps'],
            'duration' => ['duration_seconds'],
            'distance' => ['distance_meters'],
        };
        foreach ($need as $f) {
            if (!isset($d[$f])) {
                throw ValidationException::withMessages([$f => ["The $f field is required for {$ex->exercise_type} exercises."]]);
            }
        }
        if (in_array('reps', $need) && !($d['is_warmup'] ?? false) && ($d['reps'] ?? 0) < 1) {
            throw ValidationException::withMessages(['reps' => ['A completed rep-based set needs at least 1 rep.']]);
        }
        if (in_array('reps', $need)) {
            foreach (['duration_seconds', 'distance_meters'] as $f) {
                if (isset($d[$f]))
                    throw ValidationException::withMessages([$f => ["Not applicable to {$ex->exercise_type} exercises."]]);
            }
        }
    }

    /** @return array{0: WorkoutSet, 1: bool} [set, created] */
    public function record(Workout $w, array $d): array
    {
        if ($w->status !== 'in_progress')
            throw new ApiException('INVALID_STATE', 'Sets can only be added to an in-progress workout.');

        $we = WorkoutExercise::with('exercise')->where('workout_id', $w->id)->find($d['workout_exercise_id'])
            ?? throw ValidationException::withMessages(['workout_exercise_id' => ['Does not belong to this workout.']]);
        $this->validateForType($we->exercise, $d);

        if ($existing = WorkoutSet::where('user_id', $w->user_id)->where('client_uuid', $d['client_uuid'])->first()) {
            return [$this->replay($existing, $w, $d), false];
        }
        if (WorkoutSet::where('workout_exercise_id', $we->id)->where('set_number', $d['set_number'])->exists()) {
            throw ValidationException::withMessages(['set_number' => ['This set number already exists for the exercise.']]);
        }

        try {
            $set = WorkoutSet::create([
                'user_id' => $w->user_id,
                'workout_id' => $w->id,
                'workout_exercise_id' => $we->id,
                'exercise_id' => $we->exercise_id,
                'set_number' => $d['set_number'],
                'weight_kg' => $d['weight_kg'] ?? null,
                'reps' => $d['reps'] ?? null,
                'rpe' => $d['rpe'] ?? null,
                'duration_seconds' => $d['duration_seconds'] ?? null,
                'distance_meters' => $d['distance_meters'] ?? null,
                'is_warmup' => $d['is_warmup'] ?? false,
                'completed_at' => $d['completed_at'] ?? now(),
                'client_uuid' => $d['client_uuid'],
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = WorkoutSet::where('user_id', $w->user_id)->where('client_uuid', $d['client_uuid'])->firstOrFail();
            return [$this->replay($existing, $w, $d), false];
        }
        return [$set, true];
    }

    private function replay(WorkoutSet $e, Workout $w, array $d): WorkoutSet
    {
        $conflict = $e->workout_id !== $w->id;
        foreach (['workout_exercise_id', 'set_number', 'weight_kg', 'reps', 'rpe', 'duration_seconds', 'distance_meters'] as $k) {
            if (!array_key_exists($k, $d) || $d[$k] === null)
                continue;
            $same = is_numeric($d[$k]) ? (float) $d[$k] === (float) $e->$k : $d[$k] === $e->$k;
            if (!$same)
                $conflict = true;
        }
        if ($conflict) {
            throw new ApiException('IDEMPOTENCY_CONFLICT', 'client_uuid was already used with a different payload.', 409);
        }
        return $e;
    }
}
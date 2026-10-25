<?php
namespace App\Services;

use App\Models\{Workout, WorkoutSet};

class PerformanceService
{
    public function last(string $userId, string $exerciseId): ?array
    {
        $w = Workout::where('user_id', $userId)->where('status', 'completed')
            ->whereHas('sets', fn($q) => $q->where('exercise_id', $exerciseId)->where('is_warmup', false))
            ->orderByDesc('finished_at')->orderByDesc('id')->first();
        if (!$w)
            return null;

        $sets = WorkoutSet::where('workout_id', $w->id)->where('exercise_id', $exerciseId)
            ->where('is_warmup', false)->orderBy('set_number')->get();
        return ['workout' => $w, 'sets' => $sets];
    }
}
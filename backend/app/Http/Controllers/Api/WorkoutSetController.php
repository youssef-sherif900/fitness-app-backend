<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Workout, WorkoutSet};
use App\Services\{RecordService, SetService};
use App\Support\{ApiException, ApiResponse};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkoutSetController extends Controller
{
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'client_uuid' => $create ? ['required', 'uuid'] : ['prohibited'],
            'workout_exercise_id' => $create ? ['required', 'uuid'] : ['prohibited'],
            'set_number' => $create ? ['required', 'integer', 'min:1', 'max:100'] : ['prohibited'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'reps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'rpe' => ['nullable', 'numeric', 'between:1,10'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'distance_meters' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'is_warmup' => ['sometimes', 'boolean'],
            'completed_at' => ['nullable', 'date', 'before:+5 minutes'],
        ];
    }

    public function store(Request $r, string $workoutId, SetService $svc)
    {
        $w = Workout::where('user_id', $r->user()->id)->findOrFail($workoutId);
        [$set, $created] = $svc->record($w, $r->validate($this->rules(true)));
        return ApiResponse::one($set, $created ? 201 : 200);
    }

    public function update(Request $r, string $workoutId, string $setId, SetService $svc, RecordService $records)
    {
        $w = Workout::where('user_id', $r->user()->id)->findOrFail($workoutId);
        if ($w->status === 'cancelled')
            throw new ApiException('INVALID_STATE', 'Cancelled workouts are read-only.');
        $set = WorkoutSet::where('workout_id', $w->id)->findOrFail($setId);
        $d = $r->validate($this->rules(false));

        $svc->validateForType($set->workoutExercise->exercise, array_merge($set->only(['weight_kg', 'reps', 'rpe', 'duration_seconds', 'distance_meters', 'is_warmup']), $d));
        DB::transaction(function () use ($set, $d, $w, $records) {
            $set->update($d);
            if ($w->status === 'completed')
                $records->recalc($w->user_id, $set->exercise_id);
        });
        return ApiResponse::one($set->fresh());
    }

    public function destroy(Request $r, string $workoutId, string $setId, RecordService $records)
    {
        $w = Workout::where('user_id', $r->user()->id)->findOrFail($workoutId);
        if ($w->status === 'cancelled')
            throw new ApiException('INVALID_STATE', 'Cancelled workouts are read-only.');
        $set = WorkoutSet::where('workout_id', $w->id)->findOrFail($setId);
        DB::transaction(function () use ($set, $w, $records) {
            $set->delete();
            if ($w->status === 'completed')
                $records->recalc($w->user_id, $set->exercise_id);
        });
        return response()->noContent();
    }
}
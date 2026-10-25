<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Exercise, PrEvent, Program, ProgramAssignment, ProgramVersion, Workout, WorkoutExercise};
use App\Services\{PerformanceService, ProgramService, RecordService, SuggestionService};
use App\Support\{ApiException, ApiResponse};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkoutController extends Controller
{
    private function mine(Request $r, string $id): Workout
    {
        return Workout::where('user_id', $r->user()->id)->findOrFail($id);
    }

    private function detail(Workout $w): Workout
    {
        return $w->load('exercises.exercise', 'exercises.sets');
    }

    public function store(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'client_uuid' => ['required', 'uuid'],
            'program_id' => ['nullable', 'uuid', 'required_with:program_day_id'],
            'program_version' => ['nullable', 'integer'],
            'program_day_id' => ['nullable', 'uuid'],
            'started_at' => ['nullable', 'date', 'before:+5 minutes'],
        ]);

        if ($ex = Workout::where('user_id', $u->id)->where('client_uuid', $d['client_uuid'])->first()) {
            return ApiResponse::one($this->detail($ex), 200); // idempotent replay
        }

        $day = null;
        $version = null;
        if (!empty($d['program_id'])) {
            $p = Program::visibleTo($u)->findOrFail($d['program_id']);
            $version = $d['program_version']
                ?? ProgramAssignment::where('assigned_to', $u->id)->where('program_id', $p->id)->where('status', 'active')->value('program_version')
                ?? $p->published_version;
            $snap = ProgramVersion::where('program_id', $p->id)->where('version', $version)->value('snapshot')
                ?? throw ValidationException::withMessages(['program_version' => ['Unknown or unpublished program version.']]);
            if (!empty($d['program_day_id'])) {
                $day = collect($snap['days'])->firstWhere('id', $d['program_day_id'])
                    ?? throw ValidationException::withMessages(['program_day_id' => ['Day does not belong to this program version.']]);
            }
        }

        try {
            $w = DB::transaction(function () use ($u, $d, $day, $version) {
                $w = Workout::create([
                    'user_id' => $u->id,
                    'client_uuid' => $d['client_uuid'],
                    'program_id' => $d['program_id'] ?? null,
                    'program_version' => $version,
                    'program_day_id' => $d['program_day_id'] ?? null,
                    'status' => 'in_progress',
                    'started_at' => $d['started_at'] ?? now(),
                ]);
                foreach ($day['items'] ?? [] as $item) {
                    $w->exercises()->create(['exercise_id' => $item['exercise_id'], 'position' => $item['position'], 'planned' => $item]);
                }
                return $w;
            });
        } catch (UniqueConstraintViolationException) {
            $w = Workout::where('user_id', $u->id)->where('client_uuid', $d['client_uuid'])->firstOrFail();
            return ApiResponse::one($this->detail($w), 200);
        }
        return ApiResponse::one($this->detail($w), 201);
    }

    public function show(Request $r, string $workoutId)
    {
        return ApiResponse::one($this->detail($this->mine($r, $workoutId)));
    }

    public function update(Request $r, string $workoutId)
    {
        $d = $r->validate(['notes' => ['nullable', 'string', 'max:5000'], 'started_at' => ['sometimes', 'date', 'before:+5 minutes']]);
        $w = $this->mine($r, $workoutId);
        if ($w->status === 'cancelled')
            throw new ApiException('INVALID_STATE', 'Cancelled workouts are read-only.');
        $w->update($d);
        return ApiResponse::one($this->detail($w->fresh()));
    }

    public function index(Request $r)
    {
        $r->validate(['status' => ['nullable', Rule::in(['in_progress', 'completed', 'cancelled'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'program_id' => ['nullable', 'uuid']]);
        $q = Workout::where('user_id', $r->user()->id);
        if ($s = $r->query('status'))
            $q->where('status', $s);
        if ($f = $r->query('from'))
            $q->where('started_at', '>=', $f);
        if ($t = $r->query('to'))
            $q->where('started_at', '<=', $t);
        if ($p = $r->query('program_id'))
            $q->where('program_id', $p);
        return ApiResponse::page($q->orderByDesc('started_at')->orderByDesc('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function addExercise(Request $r, string $workoutId)
    {
        $d = $r->validate(['exercise_id' => ['required', 'uuid'], 'position' => ['nullable', 'integer', 'min:1']]);
        $w = $this->mine($r, $workoutId);
        if ($w->status !== 'in_progress')
            throw new ApiException('INVALID_STATE', 'Workout is not in progress.');
        $ex = Exercise::visibleTo($r->user())->whereNull('archived_at')->find($d['exercise_id'])
            ?? throw ValidationException::withMessages(['exercise_id' => ['Invalid or inaccessible exercise.']]);

        $we = DB::transaction(function () use ($w, $ex, $d) {
            $max = $w->exercises()->max('position') ?? 0;
            $pos = min($d['position'] ?? $max + 1, $max + 1);
            $w->exercises()->where('position', '>=', $pos)->increment('position');
            return $w->exercises()->create(['exercise_id' => $ex->id, 'position' => $pos]);
        });
        return ApiResponse::one($we->load('exercise'), 201);
    }

    public function reorder(Request $r, string $workoutId)
    {
        $d = $r->validate(['exercise_ids' => ['required', 'array', 'min:1'], 'exercise_ids.*' => ['uuid', 'distinct']]);
        $w = $this->mine($r, $workoutId);
        if ($w->status !== 'in_progress')
            throw new ApiException('INVALID_STATE', 'Workout is not in progress.');

        DB::transaction(function () use ($w, $d) {
            $rows = $w->exercises()->lockForUpdate()->get();
            $have = $rows->pluck('exercise_id')->sort()->values()->all();
            $want = collect($d['exercise_ids'])->sort()->values()->all();
            if ($have !== $want || $rows->pluck('exercise_id')->unique()->count() !== $rows->count()) {
                throw ValidationException::withMessages(['exercise_ids' => ['Must contain exactly the workout\'s exercises, without duplicates.']]);
            }
            foreach ($d['exercise_ids'] as $i => $exId) {
                $rows->firstWhere('exercise_id', $exId)->update(['position' => $i + 1]);
            }
        });
        return ApiResponse::one($this->detail($w->fresh()));
    }

    public function complete(Request $r, string $workoutId, RecordService $records)
    {
        $d = $r->validate(['finished_at' => ['nullable', 'date', 'before:+5 minutes']]);

        $out = DB::transaction(function () use ($r, $workoutId, $d, $records) {
            $w = Workout::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($workoutId);
            if ($w->status === 'cancelled')
                throw new ApiException('INVALID_STATE', 'A cancelled workout cannot be completed.');

            if ($w->status === 'in_progress') {
                $finished = isset($d['finished_at']) ? \Illuminate\Support\Carbon::parse($d['finished_at']) : now();
                if ($finished->lt($w->started_at) || $finished->gt($w->started_at->copy()->addHours(config('training.max_workout_hours')))) {
                    throw ValidationException::withMessages(['finished_at' => ['Outside the allowed workout duration.']]);
                }
                $w->update(['status' => 'completed', 'finished_at' => $finished, 'duration_seconds' => $w->started_at->diffInSeconds($finished)]);
                $records->processWorkout($w);
            }
            return $w;
        });

        $out->load('exercises.exercise', 'exercises.sets');
        $planned = $out->exercises->sum(fn($e) => $e->planned['sets'] ?? 0);
        $done = $out->exercises->sum(fn($e) => $e->sets->where('is_warmup', false)->count());
        return ApiResponse::one([
            'workout' => $out,
            'summary' => ['completed_working_sets' => $done, 'planned_sets' => $planned, 'uncompleted_planned_sets' => max(0, $planned - $done)],
            'new_records' => PrEvent::where('workout_id', $out->id)->get(),
        ]);
    }

    public function cancel(Request $r, string $workoutId)
    {
        $w = $this->mine($r, $workoutId);
        if ($w->status === 'completed')
            throw new ApiException('INVALID_STATE', 'A completed workout cannot be cancelled.');
        if ($w->status === 'in_progress')
            $w->update(['status' => 'cancelled']);
        return ApiResponse::one($w->fresh());
    }

    // ---- per-exercise performance ----
    public function history(Request $r, string $exerciseId)
    {
        $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        Exercise::visibleTo($r->user())->findOrFail($exerciseId);
        $q = Workout::where('user_id', $r->user()->id)->where('status', 'completed')
            ->whereHas('sets', fn($s) => $s->where('exercise_id', $exerciseId))
            ->with(['sets' => fn($s) => $s->where('exercise_id', $exerciseId)->orderBy('set_number')]);
        if ($f = $r->query('from'))
            $q->where('started_at', '>=', $f);
        if ($t = $r->query('to'))
            $q->where('started_at', '<=', $t);
        return ApiResponse::page($q->orderByDesc('started_at')->orderByDesc('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function lastPerformance(Request $r, string $exerciseId, PerformanceService $perf)
    {
        Exercise::visibleTo($r->user())->findOrFail($exerciseId);
        $last = $perf->last($r->user()->id, $exerciseId);
        if (!$last)
            return ApiResponse::one(null);
        return ApiResponse::one([
            'exercise_id' => $exerciseId,
            'workout_id' => $last['workout']->id,
            'performed_at' => $last['workout']->finished_at,
            'sets' => $last['sets']->map->only(['set_number', 'weight_kg', 'reps', 'rpe', 'duration_seconds', 'distance_meters'])->values(),
        ]);
    }

    public function nextSetSuggestion(Request $r, string $exerciseId, SuggestionService $svc)
    {
        $r->validate(['program_id' => ['nullable', 'uuid'], 'program_day_id' => ['nullable', 'uuid']]);
        $ex = Exercise::visibleTo($r->user())->findOrFail($exerciseId);
        return ApiResponse::one($svc->suggest($r->user(), $ex, $r->query('program_id'), $r->query('program_day_id')));
    }
}
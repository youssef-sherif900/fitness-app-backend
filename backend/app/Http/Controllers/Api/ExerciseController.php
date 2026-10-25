<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Exercise, ProgramItem, WorkoutExercise};
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExerciseController extends Controller
{
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'name' => [$req, 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'array'],
            'instructions.*' => ['string', 'max:500'],
            'primary_muscles' => [$req, 'array', 'min:1'],
            'primary_muscles.*' => [Rule::in(config('training.muscles'))],
            'secondary_muscles' => ['nullable', 'array'],
            'secondary_muscles.*' => [Rule::in(config('training.muscles'))],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => [Rule::in(config('training.equipment'))],
            'movement_pattern' => [$req, Rule::in(config('training.movement_patterns'))],
            'exercise_type' => [$req, Rule::in(config('training.exercise_types'))],
        ];
    }

    public function index(Request $r)
    {
        $u = $r->user();
        $r->validate([
            'scope' => ['nullable', Rule::in(['global', 'mine', 'all'])],
            'muscle' => ['nullable', Rule::in(config('training.muscles'))],
            'equipment' => ['nullable', Rule::in(config('training.equipment'))],
            'movement_pattern' => ['nullable', Rule::in(config('training.movement_patterns'))],
            'exercise_type' => ['nullable', Rule::in(config('training.exercise_types'))],
        ]);
        $q = match ($r->query('scope', 'all')) {
            'global' => Exercise::whereNull('owner_id'),
            'mine' => Exercise::where('owner_id', $u->id),
            default => Exercise::visibleTo($u),
        };
        $q->whereNull('archived_at');
        if ($s = $r->query('search'))
            $q->where('name', 'ilike', '%' . addcslashes($s, '%_') . '%');
        if ($m = $r->query('muscle'))
            $q->where(fn($w) => $w->whereJsonContains('primary_muscles', $m)->orWhereJsonContains('secondary_muscles', $m));
        if ($e = $r->query('equipment'))
            $q->whereJsonContains('equipment', $e);
        if ($mp = $r->query('movement_pattern'))
            $q->where('movement_pattern', $mp);
        if ($t = $r->query('exercise_type'))
            $q->where('exercise_type', $t);

        return ApiResponse::page($q->orderBy('name')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function show(Request $r, string $exerciseId)
    {
        return ApiResponse::one(Exercise::visibleTo($r->user())->findOrFail($exerciseId));
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules(true));
        $ex = Exercise::create($d + ['owner_id' => $r->user()->id]);
        return ApiResponse::one($ex->fresh(), 201);
    }

    private function ownCustom(Request $r, string $id): Exercise
    {
        $ex = Exercise::visibleTo($r->user())->findOrFail($id);
        if ($ex->owner_id !== $r->user()->id)
            throw new AuthorizationException(); // global = read-only
        return $ex;
    }

    public function update(Request $r, string $exerciseId)
    {
        $ex = $this->ownCustom($r, $exerciseId);
        $ex->update($r->validate($this->rules(false)));
        return ApiResponse::one($ex->fresh());
    }

    public function destroy(Request $r, string $exerciseId)
    {
        $ex = $this->ownCustom($r, $exerciseId);
        $referenced = ProgramItem::where('exercise_id', $ex->id)->exists() || WorkoutExercise::where('exercise_id', $ex->id)->exists();
        $referenced ? $ex->update(['archived_at' => now()]) : $ex->delete();
        return response()->noContent();
    }
}
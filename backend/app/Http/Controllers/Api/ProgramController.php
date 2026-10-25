<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Program, ProgramAssignment};
use App\Services\Training\ProgramService;
use App\Services\TrainerAccess;
use App\Support\{ApiException, ApiResponse};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function __construct(private ProgramService $svc)
    {
    }

    private function metaRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        [$min, $max] = config('training.days_per_week');
        return [
            'name' => [$req, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'goal' => ['nullable', Rule::in(config('training.goals'))],
            'experience_level' => ['nullable', Rule::in(config('training.levels'))],
            'days_per_week' => [$req, 'integer', "between:$min,$max"],
        ];
    }

    public function index(Request $r)
    {
        $u = $r->user();
        $r->validate(['status' => ['nullable', Rule::in(['draft', 'active', 'archived'])], 'scope' => ['nullable', Rule::in(['mine', 'assigned', 'all'])]]);
        $assigned = fn() => Program::whereIn('id', ProgramAssignment::select('program_id')->where('assigned_to', $u->id)->whereIn('status', ['active', 'paused']));
        $q = match ($r->query('scope', 'mine')) {
            'assigned' => $assigned(),
            'all' => Program::visibleTo($u),
            default => Program::where('owner_id', $u->id),
        };
        $q->where('is_template', false);
        if ($s = $r->query('status'))
            $q->where('status', $s);
        return ApiResponse::page($q->orderByDesc('updated_at')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function store(Request $r)
    {
        $u = $r->user();
        $d = $r->validate($this->metaRules(true) + ProgramService::dayRules());
        $days = $d['days'] ?? [];
        $this->svc->assertExercisesAccessible($u, $days);

        $p = DB::transaction(function () use ($u, $d, $days) {
            $p = Program::create(collect($d)->except('days')->all() + ['owner_id' => $u->id, 'status' => 'draft', 'version' => 1]);
            $this->svc->writeDays($p, $days, false);
            return $p;
        });
        return ApiResponse::one($this->svc->full($p), 201);
    }

    public function show(Request $r, string $programId)
    {
        return ApiResponse::one($this->svc->full($this->svc->visible($r->user(), $programId)));
    }

    public function update(Request $r, string $programId)
    {
        $d = $r->validate($this->metaRules(false) + ['expected_version' => ['nullable', 'integer']]);
        $p = DB::transaction(function () use ($r, $programId, $d) {
            $p = $this->svc->owned($r->user(), $programId);
            $p = Program::lockForUpdate()->find($p->id);
            if (isset($d['expected_version']) && $d['expected_version'] !== $p->version)
                throw $this->svc->versionConflict($p);
            $p->update(collect($d)->except('expected_version')->all());
            return $p;
        });
        return ApiResponse::one($this->svc->full($p));
    }

    public function updateDays(Request $r, string $programId)
    {
        $d = $r->validate(['expected_version' => ['required', 'integer']] + ProgramService::dayRules());
        $p = DB::transaction(function () use ($r, $programId, $d) {
            $p = $this->svc->owned($r->user(), $programId);
            $p = Program::lockForUpdate()->find($p->id);
            if ($p->status === 'archived')
                throw new ApiException('INVALID_STATE', 'Archived programs cannot be edited.');
            if ($d['expected_version'] !== $p->version)
                throw $this->svc->versionConflict($p);
            $this->svc->assertExercisesAccessible($r->user(), $d['days']);
            $this->svc->writeDays($p, $d['days'], true);
            $p->increment('version');
            return $p;
        });
        return ApiResponse::one($this->svc->full($p->fresh()));
    }

    public function publish(Request $r, string $programId)
    {
        $p = $this->svc->owned($r->user(), $programId);
        $this->svc->publish($p);
        return ApiResponse::one($this->svc->full($p->fresh()));
    }

    public function archive(Request $r, string $programId)
    {
        $p = $this->svc->owned($r->user(), $programId);
        $p->update(['status' => 'archived']); // history + existing workouts untouched
        return ApiResponse::one($p->fresh());
    }

    // ---- Templates ----
    public function templates(Request $r)
    {
        $r->validate(['goal' => ['nullable', Rule::in(config('training.goals'))], 'experience_level' => ['nullable', Rule::in(config('training.levels'))], 'days_per_week' => ['nullable', 'integer']]);
        $q = Program::where('is_template', true);
        foreach (['goal', 'experience_level', 'days_per_week'] as $f)
            if ($r->filled($f))
                $q->where($f, $r->query($f));
        if ($eq = $r->query('equipment')) {
            $q->whereHas('days.items', fn($i) => $i->whereIn(
                'exercise_id',
                \App\Models\Exercise::whereNull('owner_id')->whereJsonContains('equipment', $eq)->select('id')
            ));
        }
        return ApiResponse::page($q->orderBy('name')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function copyTemplate(Request $r, string $templateId)
    {
        $u = $r->user();
        $d = $r->validate(['name' => ['nullable', 'string', 'max:150']]);
        $t = Program::where('is_template', true)->with('days.items')->findOrFail($templateId);

        $copy = DB::transaction(function () use ($t, $u, $d) {
            $p = Program::create([
                'owner_id' => $u->id,
                'name' => $d['name'] ?? $t->name,
                'description' => $t->description,
                'goal' => $t->goal,
                'experience_level' => $t->experience_level,
                'days_per_week' => $t->days_per_week,
                'status' => 'draft',
                'version' => 1,
            ]);
            foreach ($t->days as $day) {
                $nd = $p->days()->create(['name' => $day->name, 'day_number' => $day->day_number]);
                foreach ($day->items as $it) {
                    $ok = \App\Models\Exercise::visibleTo($u)->whereNull('archived_at')->whereKey($it->exercise_id)->exists();
                    if ($ok)
                        $nd->items()->create($it->only(['exercise_id', 'position', 'sets', 'rep_min', 'rep_max', 'rest_seconds', 'target_rpe', 'weight_kg', 'progression_rule']));
                }
            }
            return $p;
        });
        return ApiResponse::one($this->svc->full($copy), 201);
    }

    // ---- Assignments ----
    public function assign(Request $r, string $programId)
    {
        $u = $r->user();
        $d = $r->validate(['client_id' => ['required', 'uuid'], 'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $p = $this->svc->owned($u, $programId);
        if ($p->status !== 'active' || !$p->published_version)
            throw new ApiException('INVALID_STATE', 'Publish the program before assigning it.');

        if ($d['client_id'] !== $u->id && !app(TrainerAccess::class)->canAssignTo($u, $d['client_id'])) {
            abort(404); // non-disclosure
        }
        $a = DB::transaction(function () use ($p, $d, $u) {
            ProgramAssignment::where('assigned_to', $d['client_id'])->where('status', 'active')->update(['status' => 'cancelled']);
            return ProgramAssignment::create([
                'program_id' => $p->id,
                'program_version' => $p->published_version,
                'assigned_to' => $d['client_id'],
                'assigned_by' => $u->id,
                'start_date' => $d['start_date'],
                'status' => 'active',
            ]);
        });
        return ApiResponse::one($a, 201);
    }

    public function current(Request $r)
    {
        $a = ProgramAssignment::where('assigned_to', $r->user()->id)->where('status', 'active')->latest()->first();
        if (!$a)
            return ApiResponse::one(null);
        $snap = \App\Models\ProgramVersion::where('program_id', $a->program_id)->where('version', $a->program_version)->value('snapshot');
        return ApiResponse::one(array_merge($a->toArray(), ['program_snapshot' => $snap]));
    }

    public function assignments(Request $r, string $programId)
    {
        $p = $this->svc->owned($r->user(), $programId);
        return ApiResponse::page(ProgramAssignment::where('program_id', $p->id)->orderByDesc('created_at')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function updateAssignment(Request $r, string $assignmentId)
    {
        $d = $r->validate(['status' => ['required', Rule::in(['active', 'paused', 'completed', 'cancelled'])]]);
        $a = ProgramAssignment::findOrFail($assignmentId);
        $u = $r->user();
        $prog = Program::findOrFail($a->program_id);
        $mayManage = $a->assigned_to === $u->id || $prog->owner_id === $u->id || app(TrainerAccess::class)->canEditPlan($u, $prog);
        if (!$mayManage)
            abort(404);

        $allowed = ['active' => ['paused', 'completed', 'cancelled'], 'paused' => ['active', 'completed', 'cancelled']];
        if (!in_array($d['status'], $allowed[$a->status] ?? [], true)) {
            throw new ApiException('INVALID_STATE', "Cannot change assignment from {$a->status} to {$d['status']}.");
        }
        if ($d['status'] === 'active') {
            ProgramAssignment::where('assigned_to', $a->assigned_to)->where('status', 'active')->update(['status' => 'cancelled']);
        }
        $a->update($d);
        return ApiResponse::one($a->fresh());
    }
}
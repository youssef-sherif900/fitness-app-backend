<?php
namespace App\Services\Training;

use App\Models\{Exercise, Program, ProgramDay, ProgramItem, ProgramVersion, User};
use App\Support\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProgramService
{
    public static function dayRules(string $p = 'days'): array
    {
        return [
            $p => ['array', 'min:1', 'max:14'],
            "$p.*.id" => ['sometimes', 'uuid'],
            "$p.*.name" => ['required', 'string', 'max:100'],
            "$p.*.day_number" => ['required', 'integer', 'min:1', 'max:14', 'distinct'],
            "$p.*.items" => ['present', 'array', 'max:40'],
            "$p.*.items.*.id" => ['sometimes', 'uuid'],
            "$p.*.items.*.exercise_id" => ['required', 'uuid'],
            "$p.*.items.*.position" => ['required', 'integer', 'min:1'],
            "$p.*.items.*.sets" => ['required', 'integer', 'min:1', 'max:30'],
            "$p.*.items.*.rep_range.min" => ['required', 'integer', 'min:1', 'max:200'],
            "$p.*.items.*.rep_range.max" => ['required', 'integer', 'gte:' . "$p.*.items.*.rep_range.min", 'max:200'],
            "$p.*.items.*.rest_seconds" => ['nullable', 'integer', 'min:0', 'max:' . config('training.max_rest_seconds')],
            "$p.*.items.*.target_rpe" => ['nullable', 'numeric', 'between:1,10'],
            "$p.*.items.*.weight_kg" => ['nullable', 'numeric', 'min:0', 'max:1000'],
            "$p.*.items.*.progression_rule" => ['nullable', 'array'],
            "$p.*.items.*.progression_rule.type" => ['required_with:' . "$p.*.items.*.progression_rule", Rule::in(config('training.progression_types'))],
            "$p.*.items.*.progression_rule.increment" => ['nullable', 'numeric', 'min:0', 'max:50'],
            "$p.*.items.*.progression_rule.reduction_percent" => ['nullable', 'numeric', 'between:1,50'],
            "$p.*.items.*.progression_rule.sets_multiplier" => ['nullable', 'numeric', 'between:0.1,1'],
            "$p.*.items.*.progression_rule.target_rpe" => ['nullable', 'numeric', 'between:1,10'],
        ];
    }

    public function visible(User $u, string $id): Program
    {
        return Program::visibleTo($u)->findOrFail($id);
    }

    public function owned(User $u, string $id): Program
    {
        $p = $this->visible($u, $id);
        if ($p->owner_id !== $u->id && !app(TrainerAccess::class)->canEditPlan($u, $p)) {
            throw new AuthorizationException();
        }
        return $p;
    }

    public function versionConflict(Program $p): ApiException
    {
        return new ApiException('VERSION_CONFLICT', 'The program was modified by another edit.', 409, ['current_version' => $p->version]);
    }

    public function assertExercisesAccessible(User $u, array $days): void
    {
        $ids = collect($days)->flatMap(fn($d) => collect($d['items'])->pluck('exercise_id'))->unique()->values();
        $ok = Exercise::visibleTo($u)->whereNull('archived_at')->whereIn('id', $ids)->count();
        if ($ok !== $ids->count()) {
            throw ValidationException::withMessages(['days' => ['One or more exercises are invalid or not accessible.']]);
        }
    }

    public function assertPositions(array $days): void
    {
        foreach ($days as $i => $d) {
            $pos = collect($d['items'])->pluck('position');
            if ($pos->count() !== $pos->unique()->count()) {
                throw ValidationException::withMessages(["days.$i.items" => ['Item positions must be unique within a day.']]);
            }
        }
    }

    /** Create or replace days/items. Caller wraps in a transaction. */
    public function writeDays(Program $p, array $days, bool $replace): void
    {
        $this->assertPositions($days);
        $keepDays = [];
        foreach ($days as $di => $d) {
            if (isset($d['id'])) {
                $day = $p->days()->whereKey($d['id'])->first()
                    ?? throw ValidationException::withMessages(["days.$di.id" => ['Unknown day id.']]);
                $day->update(['name' => $d['name'], 'day_number' => $d['day_number']]);
            } else {
                $day = $p->days()->create(['name' => $d['name'], 'day_number' => $d['day_number']]);
            }
            $keepDays[] = $day->id;
            $keepItems = [];
            foreach ($d['items'] as $ii => $i) {
                $attrs = [
                    'exercise_id' => $i['exercise_id'],
                    'position' => $i['position'],
                    'sets' => $i['sets'],
                    'rep_min' => $i['rep_range']['min'],
                    'rep_max' => $i['rep_range']['max'],
                    'rest_seconds' => $i['rest_seconds'] ?? null,
                    'target_rpe' => $i['target_rpe'] ?? null,
                    'weight_kg' => $i['weight_kg'] ?? null,
                    'progression_rule' => $i['progression_rule'] ?? null,
                ];
                if (isset($i['id'])) {
                    $item = $day->items()->whereKey($i['id'])->first()
                        ?? throw ValidationException::withMessages(["days.$di.items.$ii.id" => ['Unknown item id.']]);
                    $item->update($attrs);
                } else {
                    $item = $day->items()->create($attrs);
                }
                $keepItems[] = $item->id;
            }
            if ($replace)
                $day->items()->whereNotIn('id', $keepItems)->delete();
        }
        if ($replace)
            $p->days()->whereNotIn('id', $keepDays)->delete();
    }

    public function full(Program $p): Program
    {
        return $p->load('days.items');
    }

    public function snapshotArray(Program $p): array
    {
        $p->load('days.items');
        return [
            'program' => $p->only(['id', 'name', 'description', 'goal', 'experience_level', 'days_per_week']),
            'version' => $p->version,
            'days' => $p->days->map(fn($d) => $d->toArray())->all(),
        ];
    }

    public function publish(Program $p): ProgramVersion
    {
        if ($p->status === 'archived')
            throw new ApiException('INVALID_STATE', 'Archived programs cannot be published.');
        $p->load('days.items');
        if ($p->days->isEmpty() || $p->days->every(fn($d) => $d->items->isEmpty())) {
            throw ValidationException::withMessages(['days' => ['A program needs at least one day with one exercise.']]);
        }
        return DB::transaction(function () use ($p) {
            $v = ProgramVersion::firstOrCreate(
                ['program_id' => $p->id, 'version' => $p->version],
                ['snapshot' => $this->snapshotArray($p), 'published_at' => now()]
            );
            $p->update(['status' => 'active', 'published_version' => $p->version]);
            return $v;
        });
    }
}
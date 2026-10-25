<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgressionChange;
use App\Models\ProgramAssignment;
use App\Models\Program;
use App\Services\{ProgramService, ProgressionService};
use App\Support\{ApiException, ApiResponse};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressionController extends Controller
{
    public function __construct(private ProgramService $programs, private ProgressionService $prog)
    {
    }

    public function show(Request $r, string $programId, string $dayId)
    {
        $p = $this->programs->visible($r->user(), $programId);
        $day = $p->days()->with('items')->findOrFail($dayId);
        return ApiResponse::one($this->prog->forDay($p, $day, $r->user()));
    }

    public function apply(Request $r, string $programId, string $dayId)
    {
        $u = $r->user();
        $d = $r->validate(['expected_version' => ['required', 'integer'], 'exercise_id' => ['required', 'uuid']]);

        $result = DB::transaction(function () use ($u, $programId, $dayId, $d) {
            $p = $this->programs->owned($u, $programId);
            $p = Program::lockForUpdate()->find($p->id);
            if ($p->version !== $d['expected_version'])
                throw $this->programs->versionConflict($p);

            $day = $p->days()->findOrFail($dayId);
            $item = $day->items()->where('exercise_id', $d['exercise_id'])->firstOrFail();
            $rec = $this->prog->forItem($p, $day, $item, $u);

            if ($rec['proposed_prescription'] == $rec['current_prescription']) {
                throw new ApiException('NOT_ELIGIBLE', 'No progression change is currently eligible.', 422);
            }
            $item->update(['weight_kg' => $rec['proposed_prescription']['weight_kg'], 'sets' => $rec['proposed_prescription']['sets']]);
            $p->increment('version');

            if ($p->published_version) {
                $this->programs->publish($p->fresh()); // new immutable snapshot
                ProgramAssignment::where('program_id', $p->id)->where('assigned_to', $u->id)->where('status', 'active')
                    ->update(['program_version' => $p->version]);
            }
            ProgressionChange::create([
                'program_id' => $p->id,
                'program_day_id' => $day->id,
                'exercise_id' => $item->exercise_id,
                'program_version' => $p->version,
                'rule' => $rec['rule'],
                'previous' => $rec['current_prescription'],
                'new' => $rec['proposed_prescription'],
                'reason' => $rec['reason'],
                'actor_id' => $u->id,
                'created_at' => now(),
            ]);
            return ['version' => $p->version, 'prescription' => $rec['proposed_prescription']];
        });
        return ApiResponse::one($result);
    }

    public function history(Request $r, string $programId)
    {
        $p = $this->programs->visible($r->user(), $programId);
        return ApiResponse::page(ProgressionChange::where('program_id', $p->id)->orderByDesc('created_at')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }
}
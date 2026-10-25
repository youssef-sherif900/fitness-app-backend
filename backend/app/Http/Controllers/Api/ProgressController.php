<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Exercise, PersonalRecord, PrEvent};
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgressController extends Controller
{
    public function records(Request $r)
    {
        $r->validate(['exercise_id' => ['nullable', 'uuid'], 'record_type' => ['nullable', Rule::in(['max_weight', 'max_reps', 'estimated_1rm'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = PersonalRecord::where('user_id', $r->user()->id);
        if ($v = $r->query('exercise_id'))
            $q->where('exercise_id', $v);
        if ($v = $r->query('record_type'))
            $q->where('record_type', $v);
        if ($v = $r->query('from'))
            $q->where('achieved_at', '>=', $v);
        if ($v = $r->query('to'))
            $q->where('achieved_at', '<=', $v);
        return ApiResponse::page($q->orderByDesc('achieved_at')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    public function exerciseRecords(Request $r, string $exerciseId)
    {
        Exercise::visibleTo($r->user())->findOrFail($exerciseId);
        return ApiResponse::one(PersonalRecord::where('user_id', $r->user()->id)->where('exercise_id', $exerciseId)->get());
    }

    public function recordHistory(Request $r, string $exerciseId)
    {
        Exercise::visibleTo($r->user())->findOrFail($exerciseId);
        // Order: newest first (documented).
        return ApiResponse::page(PrEvent::where('user_id', $r->user()->id)->where('exercise_id', $exerciseId)
            ->orderByDesc('achieved_at')->orderBy('id')->cursorPaginate(ApiResponse::perPage($r)));
    }

    // ---- Volume ----
    private function volumeRows(string $userId, string $tz, string $start, string $end, ?string $exerciseId, ?string $muscle, bool $byWeek)
    {
        $local = "(s.completed_at at time zone ?)";
        $where = "s.user_id = ? and w.status = 'completed' and s.is_warmup = false and $local::date between ? and ?";
        $b = [$userId, $tz, $start, $end];
        if ($exerciseId) {
            $where .= ' and s.exercise_id = ?';
            $b[] = $exerciseId;
        }
        $mFilter = '';
        if ($muscle) {
            $mFilter = ' where m.muscle = ?';
        }

        $wk = $byWeek ? "date_trunc('week', $local)::date as week_start," : '';
        $grp = $byWeek ? "1, 2" : "1";
        $sql = "select $wk m.muscle as muscle_group, count(*) as working_sets
                from workout_sets s join workouts w on w.id = s.workout_id join exercises e on e.id = s.exercise_id
                cross join lateral jsonb_array_elements_text(e.primary_muscles) as m(muscle)
                where $where" . ($muscle ? ' and m.muscle = ?' : '') . " group by $grp order by $grp";
        $bind = array_merge($byWeek ? [$tz] : [], $b, $muscle ? [$muscle] : []);
        return DB::select($sql, $bind);
    }

    private function uniqueSets(string $userId, string $tz, string $start, string $end, ?string $exerciseId): int
    {
        $q = DB::table('workout_sets as s')->join('workouts as w', 'w.id', '=', 's.workout_id')
            ->where('s.user_id', $userId)->where('w.status', 'completed')->where('s.is_warmup', false)
            ->whereRaw('(s.completed_at at time zone ?)::date between ? and ?', [$tz, $start, $end]);
        if ($exerciseId)
            $q->where('s.exercise_id', $exerciseId);
        return $q->count();
    }

    public function weeklyVolume(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'week_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:week_start'],
            'exercise_id' => ['nullable', 'uuid'],
            'muscle_group' => ['nullable', Rule::in(config('training.muscles'))]
        ]);
        $tz = $u->timezone ?: 'UTC';
        $start = isset($d['week_start']) ? CarbonImmutable::parse($d['week_start'], $tz) : CarbonImmutable::now($tz)->startOfWeek();
        $end = isset($d['week_end']) ? CarbonImmutable::parse($d['week_end'], $tz) : $start->addDays(6);

        $rows = $this->volumeRows($u->id, $tz, $start->toDateString(), $end->toDateString(), $d['exercise_id'] ?? null, $d['muscle_group'] ?? null, false);
        return ApiResponse::one([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'volume_unit' => 'working_sets',
            // unique sets: NOT the sum of per-muscle counts (one set can hit several muscles)
            'total_working_sets' => $this->uniqueSets($u->id, $tz, $start->toDateString(), $end->toDateString(), $d['exercise_id'] ?? null),
            'muscle_groups' => collect($rows)->map(fn($x) => ['muscle_group' => $x->muscle_group, 'working_sets' => (int) $x->working_sets])->all(),
        ]);
    }

    public function volumeHistory(Request $r)
    {
        $u = $r->user();
        $d = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'muscle_group' => ['nullable', Rule::in(config('training.muscles'))], 'cursor' => ['nullable', 'string']]);
        $tz = $u->timezone ?: 'UTC';
        $to = CarbonImmutable::parse($d['to'] ?? 'today', $tz)->endOfWeek();
        $from = CarbonImmutable::parse($d['from'] ?? $to->subWeeks(11), $tz)->startOfWeek();
        if ($c = $r->query('cursor'))
            $from = CarbonImmutable::parse(base64_decode($c), $tz);

        $perPage = ApiResponse::perPage($r);
        $pageEnd = $from->addWeeks($perPage)->subDay();
        $hasMore = $pageEnd->lt($to);
        if (!$hasMore)
            $pageEnd = $to;

        $rows = collect($this->volumeRows($u->id, $tz, $from->toDateString(), $pageEnd->toDateString(), null, $d['muscle_group'] ?? null, true))->groupBy('week_start');
        $weeks = [];
        for ($w = $from; $w->lte($pageEnd); $w = $w->addWeek()) {
            $weeks[] = [
                'week_start' => $w->toDateString(),
                'week_end' => $w->addDays(6)->toDateString(),
                'muscle_groups' => ($rows[$w->toDateString()] ?? collect())->map(fn($x) => ['muscle_group' => $x->muscle_group, 'working_sets' => (int) $x->working_sets])->values(),
            ];
        }
        return response()->json([
            'data' => $weeks,
            'meta' => [
                'next_cursor' => $hasMore ? base64_encode($pageEnd->addDay()->toDateString()) : null,
                'has_more' => $hasMore,
            ]
        ]);
    }
}
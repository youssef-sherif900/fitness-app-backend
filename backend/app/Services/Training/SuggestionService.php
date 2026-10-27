<?php
namespace App\Services;

use App\Models\{Exercise, ProgramAssignment, ProgramVersion, Program, User};

class SuggestionService
{
    public function __construct(private PerformanceService $perf)
    {
    }

    private function snapshot(User $u, ?string $programId): ?array
    {
        if ($programId) {
            $p = Program::visibleTo($u)->find($programId);
            if (!$p)
                return null;
            $a = ProgramAssignment::where('client_id', $u->id)->where('program_id', $p->id)->where('status', 'active')->first();
            $version = $a?->program_version_id
                ? ProgramVersion::whereKey($a->program_version_id)->value('version_number')
                : $p->published_version;
        } else {
            $a = ProgramAssignment::where('client_id', $u->id)->where('status', 'active')->latest()->first();
            if (!$a)
                return null;
            $programId = $a->program_id;
            $version = $a->program_version_id
                ? ProgramVersion::whereKey($a->program_version_id)->value('version_number')
                : Program::whereKey($programId)->value('published_version');
        }
        return $version ? ProgramVersion::where('program_id', $programId)->where('version_number', $version)->value('metadata') : null;
    }

    private function prescription(User $u, string $exerciseId, ?string $programId, ?string $dayId): ?array
    {
        $snap = $this->snapshot($u, $programId);
        if (!$snap)
            return null;
        foreach ($snap['days'] as $day) {
            if ($dayId && $day['id'] !== $dayId)
                continue;
            foreach ($day['items'] as $item) {
                if ($item['exercise_id'] === $exerciseId)
                    return $item;
            }
        }
        return null;
    }

    public function suggest(User $u, Exercise $ex, ?string $programId = null, ?string $dayId = null): ?array
    {
        $last = $this->perf->last($u->id, $ex->id);
        $top = $last ? $last['sets']->sortByDesc('weight_kg')->first() : null;
        $item = $this->prescription($u, $ex->id, $programId, $dayId);

        if ($item) {
            $weight = $item['weight_kg'] ?? $top?->weight_kg;
            $reps = $item['rep_min'];
            if ($last && $weight !== null) {
                $same = $last['sets']->filter(fn($s) => (float) $s->weight_kg === (float) $weight)->max('reps');
                if ($same)
                    $reps = min($same + 1, $item['rep_max']);
            }
            return [
                'exercise_id' => $ex->id,
                'suggested_weight_kg' => $weight,
                'suggested_reps' => $reps,
                'target_rpe' => $item['target_rpe'],
                'reason' => 'Based on your active program prescription.',
                'source' => 'program_prescription',
            ];
        }
        if ($top) {
            return [
                'exercise_id' => $ex->id,
                'suggested_weight_kg' => $top->weight_kg,
                'suggested_reps' => $top->reps,
                'target_rpe' => $top->rpe,
                'reason' => 'Based on your last completed performance.',
                'source' => 'last_performance',
            ];
        }
        return null;
    }
}
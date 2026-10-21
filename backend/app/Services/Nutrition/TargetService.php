<?php

namespace App\Services\Nutrition;

use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Applies the calculator to a user's stored profile + goal and persists the resulting target. */
class TargetService
{
    public function __construct(private readonly TargetCalculator $calculator) {}

    /** @return array{bmr:int,tdee:int,kcal:int,protein_g:int,carbs_g:int,fat_g:int} (throws GuardrailViolation) */
    public function compute(User $user, ?array $profile = null, ?array $goal = null): array
    {
        $p = $profile ?? $user->profile?->toArray() ?? throw new \DomainException('Profile is required.');
        $g = $goal ?? $user->activeGoal()?->toArray() ?? ['type' => 'maintain', 'weekly_rate_kg' => 0];

        return $this->calculator->calculate(
            $p['sex'], (float) $p['weight_kg'], (float) $p['height_cm'],
            Carbon::parse($p['birth_date'])->age, $p['activity_level'],
            $g['type'], (float) ($g['weekly_rate_kg'] ?? 0),
        );
    }

    /** True when a trainer set the current targets: automatic changes must not overwrite them (COACH-3). */
    public function isTrainerManaged(User $user): bool
    {
        return $user->currentTarget()?->source === 'trainer';
    }

    public function store(User $user, array $macros, string $source, string $reason): Target
    {
        return Target::create([
            'user_id' => $user->id,
            'kcal' => $macros['kcal'], 'protein_g' => $macros['protein_g'],
            'carbs_g' => $macros['carbs_g'], 'fat_g' => $macros['fat_g'],
            'source' => $source, 'reason' => $reason, 'effective_from' => now()->toDateString(),
        ]);
    }

    /** Recalculate from profile/goal and store an automatic target, unless a trainer manages targets. */
    public function recalculate(User $user, string $reason): ?Target
    {
        if ($this->isTrainerManaged($user)) {
            return null;
        }

        return $this->store($user, $this->compute($user), 'auto', $reason);
    }
}

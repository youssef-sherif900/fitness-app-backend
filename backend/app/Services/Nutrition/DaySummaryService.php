<?php

namespace App\Services\Nutrition;

use App\Models\FoodLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** LOG-3: per-day totals, cached in Redis and invalidated whenever a log changes (section 7). */
class DaySummaryService
{
    public function day(User $user, string $date): array
    {
        return Cache::remember($this->key($user->id, $date), 3600, function () use ($user, $date) {
            $rows = FoodLog::where('user_id', $user->id)->where('logged_on', $date)
                ->selectRaw('meal, sum(kcal) as kcal, sum(protein_g) as protein_g, sum(carbs_g) as carbs_g, sum(fat_g) as fat_g, count(*) as items')
                ->groupBy('meal')->get();

            $totals = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
            $meals = [];
            foreach ($rows as $r) {
                $meals[$r->meal] = ['items' => (int) $r->items] + array_map(fn ($k) => round((float) $r->$k, 1), array_combine(array_keys($totals), array_keys($totals)));
                foreach ($totals as $k => $_) {
                    $totals[$k] += (float) $r->$k;
                }
            }

            return ['date' => $date, 'intake' => array_map(fn ($v) => round($v, 1), $totals), 'meals' => $meals];
        });
    }

    public function range(User $user, string $from, string $to): array
    {
        $days = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $days[] = $this->day($user, date('Y-m-d', $d));
        }
        $target = $user->currentTarget();

        return [
            'days' => $days,
            'target' => $target?->only(['kcal', 'protein_g', 'carbs_g', 'fat_g', 'source']),
            // Calories burned need an activity/workout energy model, which is not in the PRD yet. Reported as null, not 0.
            'burn_kcal' => null,
        ];
    }

    public function forget(string $userId, string $date): void
    {
        Cache::forget($this->key($userId, $date));
    }

    private function key(string $userId, string $date): string
    {
        return "daysum:{$userId}:{$date}";
    }
}

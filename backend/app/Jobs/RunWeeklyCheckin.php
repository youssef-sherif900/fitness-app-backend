<?php

namespace App\Jobs;

use App\Models\Checkin;
use App\Models\FoodLog;
use App\Models\User;
use App\Models\Weight;
use App\Services\Nutrition\AdaptiveTargetService;
use App\Services\Nutrition\TargetService;
use App\Services\Nutrition\TrendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CHK-1 / NUT-1. Idempotent: one check-in per (user, week_start); a re-run of a finished one does nothing.
 * Trainer-managed targets and users with an active trainer are never changed automatically: the proposal
 * is stored for the trainer to approve or override (COACH-3).
 */
class RunWeeklyCheckin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $userId, public string $weekStart)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TrendService $trend, AdaptiveTargetService $adaptive, TargetService $targets): void
    {
        $user = User::find($this->userId);
        $goal = $user?->activeGoal();
        $current = $user?->currentTarget();
        if (! $user || ! $goal || ! $current) {
            return;
        }

        $checkin = Checkin::firstOrCreate(['user_id' => $user->id, 'week_start' => $this->weekStart], ['metrics' => [], 'status' => 'pending']);
        if (! empty($checkin->metrics)) {
            return;
        }

        $days = config('fitness.adaptive.window_days');
        $end = Carbon::parse($this->weekStart)->subDay();
        $start = $end->copy()->subDays($days - 1);

        $weights = Weight::where('user_id', $user->id)->whereBetween('measured_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('measured_at')->get();
        $series = $trend->compute($weights->map(fn ($w) => ['date' => $w->measured_on->toDateString(), 'weight' => $w->value_kg])->all());
        $slope = $trend->weeklySlope($series);

        $loggedDays = FoodLog::where('user_id', $user->id)->whereBetween('logged_on', [$start->toDateString(), $end->toDateString()])
            ->distinct()->count('logged_on');
        $adherence = round($loggedDays / $days, 2);

        $expected = match ($goal->type) {
            'lose' => -$goal->weekly_rate_kg,
            'gain' => $goal->weekly_rate_kg,
            default => 0.0,
        };

        $proposal = $adaptive->propose($current->kcal, $expected, $slope, $weights->count(), $adherence);

        $hasTrainer = DB::table('trainer_clients')->where('client_id', $user->id)->where('status', 'active')->exists();
        $needsApproval = $hasTrainer || $targets->isTrainerManaged($user);

        $status = 'skipped';
        if ($proposal['applied']) {
            if ($needsApproval) {
                $status = 'pending'; // trainer approves or overrides
            } else {
                $macros = $targets->compute($user);
                $scaled = $this->scaleMacros($macros, $proposal['new_kcal'], $current->kcal);
                $targets->store($user, $scaled, 'auto', $proposal['reason']);
                $status = 'applied';
            }
        }

        $checkin->update([
            'metrics' => ['weigh_ins' => $weights->count(), 'trend_kg_per_week' => $slope === null ? null : round($slope, 2),
                'expected_kg_per_week' => $expected, 'adherence' => $adherence, 'current_kcal' => $current->kcal],
            'proposed_changes' => $proposal, 'status' => $status,
        ]);
    }

    /** Keep the current protein; shift the kcal change into carbs/fat in the existing ratio. */
    private function scaleMacros(array $base, int $newKcal, int $oldKcal): array
    {
        $ratio = $newKcal / max(1, $oldKcal);

        return [
            'kcal' => $newKcal, 'protein_g' => $base['protein_g'],
            'carbs_g' => (int) round($base['carbs_g'] * $ratio), 'fat_g' => (int) round($base['fat_g'] * $ratio),
        ];
    }
}

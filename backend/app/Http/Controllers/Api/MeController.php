<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\TargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    public function __construct(private readonly TargetService $targets) {}

    public function show(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => $u->only(['id', 'email', 'name', 'is_client', 'is_trainer', 'email_verified_at', 'locale', 'units', 'timezone']) + [
            'profile' => $u->profile,
            'goal' => $u->activeGoal(),
            'target' => $u->currentTarget(),
        ]]);
    }

    /** PROF-1/2/3: save profile + goal; the target is calculated and guardrails are enforced before anything is saved. */
    public function updateProfile(Request $request): JsonResponse
    {
        $d = $request->validate([
            'birth_date' => 'required|date|before:-13 years|after:1900-01-01',
            'sex' => 'required|in:male,female',
            'height_cm' => 'required|numeric|between:90,250',
            'weight_kg' => 'required|numeric|between:30,350',
            'activity_level' => 'required|in:'.implode(',', array_keys(config('fitness.activity_factors'))),
            'diet_preferences' => 'nullable|array',
            'allergies' => 'nullable|array',
            'goal' => 'required|array',
            'goal.type' => 'required|in:lose,maintain,gain',
            'goal.target_weight_kg' => 'nullable|numeric|between:30,350',
            'goal.weekly_rate_kg' => 'nullable|numeric|between:0,2',
            'timezone' => 'sometimes|timezone',
            'units' => 'sometimes|in:metric,imperial',
            'locale' => 'sometimes|string|max:8',
            'name' => 'sometimes|nullable|string|max:120',
        ]);

        $user = $request->user();
        $profile = collect($d)->only(['birth_date', 'sex', 'height_cm', 'weight_kg', 'activity_level', 'diet_preferences', 'allergies'])->all();
        $goal = $d['goal'] + ['weekly_rate_kg' => 0];

        $macros = $this->targets->compute($user, $profile, $goal); // throws GuardrailViolation -> 422, nothing saved

        DB::transaction(function () use ($user, $profile, $goal, $d, $macros) {
            Profile::updateOrCreate(['user_id' => $user->id], $profile);
            $user->goals()->where('is_active', true)->update(['is_active' => false]);
            $user->goals()->create($goal + ['is_active' => true]);
            $user->update(collect($d)->only(['timezone', 'units', 'locale', 'name'])->all());

            if (! $this->targets->isTrainerManaged($user)) {
                $this->targets->store($user, $macros, 'auto', 'Calculated from your profile and goal.');
            }
        });

        return $this->show($request->setUserResolver(fn () => $user->fresh()));
    }

    /** PRIV-1: export everything we hold about the user. */
    public function export(Request $request): JsonResponse
    {
        $u = $request->user();
        $tables = ['goals', 'targets', 'food_logs', 'saved_meals', 'weights', 'measurements', 'workouts', 'checkins'];
        $out = ['user' => $u->makeVisible([])->toArray(), 'profile' => $u->profile];
        foreach ($tables as $t) {
            $out[$t] = DB::table($t)->where('user_id', $u->id)->get();
        }

        return response()->json(['data' => $out]);
    }

    /** AUTH-4: immediate sign-out + soft delete; PurgeExpiredData removes everything after the configured window. */
    public function destroy(Request $request): JsonResponse
    {
        $u = $request->user();
        $u->tokens()->delete();
        $u->delete();

        return response()->json(['data' => ['purge_after_days' => config('fitness.account_deletion_days')]], 202);
    }
}

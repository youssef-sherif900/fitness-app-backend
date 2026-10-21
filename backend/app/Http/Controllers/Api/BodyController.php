<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Weight;
use App\Services\Nutrition\TrendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BodyController extends Controller
{
    public function __construct(private readonly TrendService $trend) {}

    /** BODY-1: weigh-ins plus the smoothed trend. History before `from` warms up the trend so charts do not restart at zero. */
    public function weights(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d']);
        $to = $d['to'] ?? now()->toDateString();
        $from = $d['from'] ?? Carbon::parse($to)->subDays(90)->toDateString();

        $rows = Weight::where('user_id', $request->user()->id)
            ->whereBetween('measured_on', [Carbon::parse($from)->subDays(30)->toDateString(), $to])
            ->orderBy('measured_at')->get();

        $series = $this->trend->compute($rows->map(fn ($w) => ['date' => $w->measured_on->toDateString(), 'weight' => $w->value_kg])->all());
        $visible = array_values(array_filter($series, fn ($p) => $p['date'] >= $from));

        return response()->json(['data' => [
            'entries' => $rows->filter(fn ($w) => $w->measured_on->toDateString() >= $from)->values(),
            'trend' => $visible,
            'slope_kg_per_week' => ($s = $this->trend->weeklySlope($visible)) === null ? null : round($s, 2),
        ]]);
    }

    /** Idempotent on client_uuid, like food logs. */
    public function storeWeight(Request $request): JsonResponse
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'value_kg' => 'required|numeric|between:20,500',
            'measured_at' => 'nullable|date|before_or_equal:+1 day',
        ]);
        $user = $request->user();

        $existing = Weight::withTrashed()->where('user_id', $user->id)->where('client_uuid', $d['client_uuid'])->first();
        if ($existing) {
            return response()->json(['data' => $existing], 200);
        }

        $at = isset($d['measured_at']) ? Carbon::parse($d['measured_at']) : now();
        try {
            $w = Weight::create([
                'user_id' => $user->id, 'client_uuid' => $d['client_uuid'], 'value_kg' => $d['value_kg'],
                'measured_at' => $at, 'measured_on' => $at->copy()->setTimezone($user->timezone ?: 'UTC')->toDateString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['data' => Weight::withTrashed()->where('user_id', $user->id)->where('client_uuid', $d['client_uuid'])->firstOrFail()], 200);
        }

        return response()->json(['data' => $w], 201);
    }

    public function destroyWeight(Request $request, string $weight): JsonResponse
    {
        Weight::where('user_id', $request->user()->id)->findOrFail($weight)->delete();

        return response()->json(null, 204);
    }
}

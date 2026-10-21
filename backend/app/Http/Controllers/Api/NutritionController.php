<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Nutrition\TargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutritionController extends Controller
{
    public function __construct(private readonly TargetService $targets) {}

    public function current(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->currentTarget()]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->targets()->orderByDesc('effective_from')->orderByDesc('created_at')->limit(50)->get()]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        abort_unless($request->user()->profile, 422, 'Complete your profile first.');
        $target = $this->targets->recalculate($request->user(), 'Recalculated on request.');

        return response()->json(['data' => $target ?? $request->user()->currentTarget(), 'meta' => ['trainer_managed' => $target === null]]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\FoodServing;
use App\Services\Nutrition\DaySummaryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LogController extends Controller
{
    public function __construct(private readonly DaySummaryService $summaries) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => 'required_without:from|date', 'from' => 'required_without:date|date', 'to' => 'required_with:from|date|after_or_equal:from']);
        $from = $d['date'] ?? $d['from'];
        $to = $d['date'] ?? $d['to'];

        $logs = FoodLog::where('user_id', $request->user()->id)->whereBetween('logged_on', [$from, $to])
            ->orderBy('logged_at')->orderBy('id')->limit(1000)->get();

        return response()->json(['data' => $logs]);
    }

    /** LOG-1: idempotent create. Retrying the same client_uuid returns the original row (200) instead of a duplicate. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'meal' => 'required|in:breakfast,lunch,dinner,snack',
            'qty' => 'required|numeric|gt:0|max:10000',
            'logged_at' => 'nullable|date',
            'logged_on' => 'nullable|date_format:Y-m-d',
            'food_id' => 'nullable|uuid|required_with:serving_id',
            'serving_id' => 'nullable|uuid|required_with:food_id',
            'name' => 'required_without:food_id|string|max:255',
            'kcal' => 'required_without:food_id|numeric|min:0|max:20000',
            'protein_g' => 'nullable|numeric|min:0',
            'carbs_g' => 'nullable|numeric|min:0',
            'fat_g' => 'nullable|numeric|min:0',
        ]);
        $user = $request->user();

        $existing = FoodLog::withTrashed()->where('user_id', $user->id)->where('client_uuid', $d['client_uuid'])->first();
        if ($existing) {
            return response()->json(['data' => $existing], 200);
        }

        $loggedAt = isset($d['logged_at']) ? Carbon::parse($d['logged_at']) : now();
        $row = [
            'user_id' => $user->id, 'client_uuid' => $d['client_uuid'], 'meal' => $d['meal'], 'qty' => $d['qty'],
            'logged_at' => $loggedAt,
            'logged_on' => $d['logged_on'] ?? $loggedAt->copy()->setTimezone($user->timezone ?: 'UTC')->toDateString(),
        ] + $this->nutrition($request, $d);

        try {
            $log = FoodLog::create($row);
        } catch (UniqueConstraintViolationException) { // two concurrent retries: the loser reads the winner's row
            $log = FoodLog::withTrashed()->where('user_id', $user->id)->where('client_uuid', $d['client_uuid'])->firstOrFail();

            return response()->json(['data' => $log], 200);
        }
        $this->summaries->forget($user->id, $log->logged_on->toDateString());

        return response()->json(['data' => $log], 201);
    }

    public function update(Request $request, string $log): JsonResponse
    {
        $row = FoodLog::where('user_id', $request->user()->id)->findOrFail($log);
        $d = $request->validate([
            'meal' => 'sometimes|in:breakfast,lunch,dinner,snack',
            'qty' => 'sometimes|numeric|gt:0|max:10000',
            'logged_on' => 'sometimes|date_format:Y-m-d',
        ]);

        $oldDate = $row->logged_on->toDateString();
        if (isset($d['qty']) && $row->qty > 0) {
            $f = $d['qty'] / $row->qty; // scale the snapshot, so edits work for quick-add entries too
            foreach (['kcal', 'protein_g', 'carbs_g', 'fat_g'] as $k) {
                $row->$k = round($row->$k * $f, 2);
            }
        }
        $row->fill($d)->save();

        $this->summaries->forget($row->user_id, $oldDate);
        $this->summaries->forget($row->user_id, $row->logged_on->toDateString());

        return response()->json(['data' => $row->fresh()]);
    }

    public function destroy(Request $request, string $log): JsonResponse
    {
        $row = FoodLog::where('user_id', $request->user()->id)->findOrFail($log);
        $row->delete(); // soft delete: kept as a tombstone for sync
        $this->summaries->forget($row->user_id, $row->logged_on->toDateString());

        return response()->json(null, 204);
    }

    /** LOG-2: copy a meal or a whole day to another date. */
    public function copy(Request $request): JsonResponse
    {
        $d = $request->validate([
            'from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d',
            'meal' => 'sometimes|in:breakfast,lunch,dinner,snack', 'to_meal' => 'sometimes|in:breakfast,lunch,dinner,snack',
        ]);
        $user = $request->user();

        $source = FoodLog::where('user_id', $user->id)->where('logged_on', $d['from_date'])
            ->when(isset($d['meal']), fn ($q) => $q->where('meal', $d['meal']))->get();

        $created = $source->map(fn (FoodLog $l) => FoodLog::create([
            'user_id' => $user->id, 'client_uuid' => (string) Str::uuid(), 'food_id' => $l->food_id, 'serving_id' => $l->serving_id,
            'name' => $l->name, 'qty' => $l->qty, 'meal' => $d['to_meal'] ?? $l->meal,
            'logged_on' => $d['to_date'], 'logged_at' => Carbon::parse($d['to_date'])->setTimeFrom($l->logged_at),
            'kcal' => $l->kcal, 'protein_g' => $l->protein_g, 'carbs_g' => $l->carbs_g, 'fat_g' => $l->fat_g,
        ]));
        $this->summaries->forget($user->id, $d['to_date']);

        return response()->json(['data' => $created->values()], 201);
    }

    /** LOG-3: daily + range summaries. */
    public function summary(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(Carbon::parse($d['from'])->diffInDays($d['to']) > 62, 422, 'Range is limited to 62 days.');

        return response()->json(['data' => $this->summaries->range($request->user(), $d['from'], $d['to'])]);
    }

    /** Snapshot nutrients from a catalog serving (x qty) or from the quick-add values. */
    private function nutrition(Request $request, array $d): array
    {
        if (empty($d['food_id'])) {
            return [
                'name' => $d['name'], 'kcal' => $d['kcal'],
                'protein_g' => $d['protein_g'] ?? 0, 'carbs_g' => $d['carbs_g'] ?? 0, 'fat_g' => $d['fat_g'] ?? 0,
            ];
        }

        $food = Food::where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', $request->user()->id))->findOrFail($d['food_id']);
        $serving = FoodServing::where('food_id', $food->id)->findOrFail($d['serving_id']);
        $q = (float) $d['qty'];

        return [
            'food_id' => $food->id, 'serving_id' => $serving->id, 'name' => $food->name,
            'kcal' => round($serving->kcal * $q, 2), 'protein_g' => round($serving->protein_g * $q, 2),
            'carbs_g' => round($serving->carbs_g * $q, 2), 'fat_g' => round($serving->fat_g * $q, 2),
        ];
    }
}

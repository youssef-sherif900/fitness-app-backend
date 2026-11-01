<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedMeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** LOG-2: saved meals and recipes (one item = a single food, several items = a recipe). */
class SavedMealController extends Controller
{
    private const ITEM_FIELDS = [
        'food_id', 'serving_id', 'name', 'qty', 'meal',
        'kcal', 'protein_g', 'carbs_g', 'fat_g',
    ];

    public function index(Request $request): JsonResponse
    {
        $meals = SavedMeal::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $meals]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $meal = SavedMeal::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'items' => $this->items($data['items']),
        ]);

        return response()->json(['data' => $meal], 201);
    }

    public function show(Request $request, string $savedMeal): JsonResponse
    {
        return response()->json(['data' => $this->owned($request, $savedMeal)]);
    }

    public function update(Request $request, string $savedMeal): JsonResponse
    {
        $meal = $this->owned($request, $savedMeal);
        $data = $this->validated($request, false);
        if (isset($data['name'])) {
            $meal->name = $data['name'];
        }
        if (isset($data['items'])) {
            $meal->items = $this->items($data['items']);
        }
        $meal->save();

        return response()->json(['data' => $meal->fresh()]);
    }

    public function destroy(Request $request, string $savedMeal): JsonResponse
    {
        $this->owned($request, $savedMeal)->delete();

        return response()->json(null, 204);
    }

    private function owned(Request $request, string $id): SavedMeal
    {
        return SavedMeal::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:120',
            'items' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:50',
            'items.*.food_id' => 'nullable|uuid',
            'items.*.serving_id' => 'nullable|uuid',
            'items.*.name' => 'required|string|max:255',
            'items.*.qty' => 'required|numeric|gt:0|max:10000',
            'items.*.meal' => 'nullable|in:breakfast,lunch,dinner,snack',
            'items.*.kcal' => 'required|numeric|min:0|max:20000',
            'items.*.protein_g' => 'nullable|numeric|min:0',
            'items.*.carbs_g' => 'nullable|numeric|min:0',
            'items.*.fat_g' => 'nullable|numeric|min:0',
        ]);
    }

    /** Keep only known snapshot fields, so a saved meal cannot inject arbitrary JSON into the client. */
    private function items(array $items): array
    {
        return array_map(
            fn (array $item) => array_intersect_key($item, array_flip(self::ITEM_FIELDS)),
            array_values($items),
        );
    }
}

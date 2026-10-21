<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\FoodReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FoodController extends Controller
{
    /** Global foods plus the caller's own custom foods. */
    private function visible(Request $r): Builder
    {
        return Food::query()->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', $r->user()->id));
    }

    /** FOOD-1: search (trigram ILIKE), cursor-paginated. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:100', 'verified' => 'sometimes|boolean']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%';

        $page = $this->visible($request)
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('brand', 'ilike', $like))
            ->when($request->boolean('verified'), fn ($q) => $q->where('verified', true))
            ->with('servings')
            ->orderByDesc('verified')->orderBy('name')->orderBy('id')
            ->cursorPaginate(25);

        return response()->json($page);
    }

    /** FOOD-1: barcode lookup (global foods are cached; custom foods are looked up fresh). */
    public function barcode(Request $request, string $code): JsonResponse
    {
        abort_unless(preg_match('/^\d{6,14}$/', $code), 422, 'Invalid barcode.');

        $global = Cache::remember("barcode:{$code}", 3600, fn () => Food::whereNull('owner_id')->where('barcode', $code)->with('servings')->first());
        $food = $global ?? $this->visible($request)->where('barcode', $code)->with('servings')->first();
        abort_unless($food, 404);

        return response()->json(['data' => $food]);
    }

    public function show(Request $request, string $food): JsonResponse
    {
        return response()->json(['data' => $this->visible($request)->with('servings')->findOrFail($food)]);
    }

    /** FOOD-2: custom foods (never marked verified). */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'barcode' => 'nullable|string|regex:/^\d{6,14}$/',
            'language' => 'sometimes|string|max:8',
            'servings' => 'required|array|min:1|max:10',
            'servings.*.label' => 'required|string|max:64',
            'servings.*.grams' => 'nullable|numeric|min:0',
            'servings.*.kcal' => 'required|numeric|min:0|max:20000',
            'servings.*.protein_g' => 'nullable|numeric|min:0',
            'servings.*.carbs_g' => 'nullable|numeric|min:0',
            'servings.*.fat_g' => 'nullable|numeric|min:0',
            'servings.*.fiber_g' => 'nullable|numeric|min:0',
        ]);

        $food = DB::transaction(function () use ($d, $request) {
            $food = Food::create([
                'owner_id' => $request->user()->id, 'source' => 'custom', 'verified' => false,
                'name' => $d['name'], 'brand' => $d['brand'] ?? null, 'barcode' => $d['barcode'] ?? null,
                'language' => $d['language'] ?? $request->user()->locale,
            ]);
            foreach (array_values($d['servings']) as $i => $s) {
                $food->servings()->create($s + ['is_default' => $i === 0]);
            }

            return $food->load('servings');
        });

        return response()->json(['data' => $food], 201);
    }

    /** FOOD-2: report a wrong entry. */
    public function report(Request $request, string $food): JsonResponse
    {
        $f = $this->visible($request)->findOrFail($food);
        $d = $request->validate(['reason' => 'required|string|max:1000']);
        $r = FoodReport::create(['food_id' => $f->id, 'user_id' => $request->user()->id, 'reason' => $d['reason']]);

        return response()->json(['data' => $r], 201);
    }
}

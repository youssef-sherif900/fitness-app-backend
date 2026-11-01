<?php

namespace Tests\Feature;

use App\Models\Food;

class SavedMealTest extends ApiTestCase
{
    private function items(): array
    {
        return [[
            'name' => 'Oats', 'qty' => 1, 'meal' => 'breakfast',
            'kcal' => 150, 'protein_g' => 5, 'carbs_g' => 27, 'fat_g' => 3,
        ]];
    }

    public function test_saved_meals_can_be_created_listed_updated_and_deleted(): void
    {
        $u = $this->client();

        $id = $this->as($u)->postJson('/api/v1/nutrition/saved-meals', ['name' => 'Morning oats', 'items' => $this->items()])
            ->assertCreated()->assertJsonPath('data.name', 'Morning oats')->json('data.id');

        $this->as($u)->getJson('/api/v1/nutrition/saved-meals')->assertOk()->assertJsonCount(1, 'data');
        $this->as($u)->patchJson("/api/v1/nutrition/saved-meals/{$id}", ['name' => 'Oats v2'])->assertOk()
            ->assertJsonPath('data.name', 'Oats v2');
        $this->as($u)->deleteJson("/api/v1/nutrition/saved-meals/{$id}")->assertNoContent();
        $this->as($u)->getJson('/api/v1/nutrition/saved-meals')->assertJsonCount(0, 'data');
    }

    public function test_other_users_cannot_touch_a_saved_meal(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $id = $this->as($owner)->postJson('/api/v1/nutrition/saved-meals', ['name' => 'Mine', 'items' => $this->items()])
            ->json('data.id');

        $this->as($other)->getJson("/api/v1/nutrition/saved-meals/{$id}")->assertNotFound();
        $this->as($other)->patchJson("/api/v1/nutrition/saved-meals/{$id}", ['name' => 'Hers'])->assertNotFound();
        $this->as($other)->deleteJson("/api/v1/nutrition/saved-meals/{$id}")->assertNotFound();
    }

    public function test_saved_meal_requires_at_least_one_item(): void
    {
        $this->as($this->client())->postJson('/api/v1/nutrition/saved-meals', ['name' => 'Empty', 'items' => []])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['items']]]);
    }

    public function test_a_client_can_edit_their_custom_food_servings(): void
    {
        $u = $this->client();
        $food = $this->as($u)->postJson('/api/v1/foods', [
            'name' => 'Shake', 'servings' => [['label' => '1 scoop', 'kcal' => 120]],
        ])->json('data');

        $this->as($u)->patchJson("/api/v1/foods/{$food['id']}", [
            'name' => 'Shake v2',
            'servings' => [['label' => '2 scoops', 'kcal' => 240, 'protein_g' => 40]],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Shake v2')
            ->assertJsonPath('data.servings.0.label', '2 scoops')
            ->assertJsonPath('data.servings.0.kcal', 240);

        $this->assertSame(1, Food::find($food['id'])->servings()->count());
    }

    public function test_global_foods_cannot_be_edited(): void
    {
        $u = $this->client();
        $food = Food::create(['name' => 'Public oats', 'source' => 'seed', 'verified' => true]);
        $food->servings()->create(['label' => '40 g', 'kcal' => 150, 'is_default' => true]);

        $this->as($u)->patchJson("/api/v1/foods/{$food->id}", ['name' => 'Mine now'])->assertForbidden();
    }
}

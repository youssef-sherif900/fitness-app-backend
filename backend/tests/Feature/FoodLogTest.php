<?php

namespace Tests\Feature;

use App\Models\Food;
use Illuminate\Support\Str;

class FoodLogTest extends ApiTestCase
{
    private function food(): Food
    {
        $f = Food::create(['name' => 'Test Oats', 'source' => 'seed', 'verified' => true]);
        $f->servings()->create(['label' => '40 g', 'grams' => 40, 'kcal' => 150, 'protein_g' => 5, 'carbs_g' => 27, 'fat_g' => 3, 'is_default' => true]);

        return $f->load('servings');
    }

    private function payload(Food $f, ?string $uuid = null, float $qty = 2): array
    {
        return ['client_uuid' => $uuid ?? (string) Str::uuid(), 'food_id' => $f->id, 'serving_id' => $f->servings[0]->id,
            'qty' => $qty, 'meal' => 'breakfast', 'logged_at' => '2026-10-17T07:30:00Z'];
    }

    /** Critical test case 3: retried/replayed offline writes never duplicate. */
    public function test_retrying_the_same_client_uuid_does_not_duplicate(): void
    {
        $u = $this->client();
        $p = $this->payload($this->food());

        $this->as($u)->postJson('/api/v1/logs', $p)->assertCreated()->assertJsonPath('data.kcal', 300);
        $this->as($u)->postJson('/api/v1/logs', $p)->assertOk();

        $this->assertDatabaseCount('food_logs', 1);
    }

    public function test_deleted_log_is_not_resurrected_by_a_replay(): void
    {
        $u = $this->client();
        $p = $this->payload($this->food());
        $id = $this->as($u)->postJson('/api/v1/logs', $p)->json('data.id');
        $this->as($u)->deleteJson("/api/v1/logs/{$id}")->assertNoContent();

        $this->as($u)->postJson('/api/v1/logs', $p)->assertOk();

        $this->assertSoftDeleted('food_logs', ['id' => $id]);
        $this->assertSame(0.0, (float) $this->as($u)->getJson('/api/v1/logs/summary?from=2026-10-17&to=2026-10-17')->json('data.days.0.intake.kcal'));
    }

    public function test_summary_totals_and_cache_invalidation(): void
    {
        $u = $this->client();
        $f = $this->food();

        $this->as($u)->postJson('/api/v1/logs', $this->payload($f, null, 2))->assertCreated();
        $this->as($u)->getJson('/api/v1/logs/summary?from=2026-10-17&to=2026-10-17')->assertJsonPath('data.days.0.intake.kcal', 300);

        $this->as($u)->postJson('/api/v1/logs', $this->payload($f, null, 1))->assertCreated(); // must invalidate the cached day
        $this->as($u)->getJson('/api/v1/logs/summary?from=2026-10-17&to=2026-10-17')
            ->assertJsonPath('data.days.0.intake.kcal', 450)->assertJsonPath('data.days.0.meals.breakfast.items', 2)
            ->assertJsonPath('data.burn_kcal', null);
    }

    public function test_users_cannot_touch_each_others_logs(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $id = $this->as($owner)->postJson('/api/v1/logs', $this->payload($this->food()))->json('data.id');

        $this->as($other)->patchJson("/api/v1/logs/{$id}", ['qty' => 5])->assertNotFound();
        $this->as($other)->deleteJson("/api/v1/logs/{$id}")->assertNotFound();
        $this->as($other)->getJson('/api/v1/logs?date=2026-10-17')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_users_custom_foods_are_invisible_and_unloggable(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $food = $this->as($owner)->postJson('/api/v1/foods', ['name' => 'Secret Shake', 'servings' => [['label' => '1 cup', 'kcal' => 200]]])
            ->assertCreated()->assertJsonPath('data.verified', false)->json('data');

        $this->as($other)->getJson("/api/v1/foods/{$food['id']}")->assertNotFound();
        $this->as($other)->getJson('/api/v1/foods?q=Secret')->assertJsonCount(0, 'data');
        $this->as($other)->postJson('/api/v1/logs', ['client_uuid' => (string) Str::uuid(), 'food_id' => $food['id'],
            'serving_id' => $food['servings'][0]['id'], 'qty' => 1, 'meal' => 'snack'])->assertNotFound();
    }

    public function test_update_rescales_nutrients(): void
    {
        $u = $this->client();
        $id = $this->as($u)->postJson('/api/v1/logs', $this->payload($this->food(), null, 2))->json('data.id');

        $this->as($u)->patchJson("/api/v1/logs/{$id}", ['qty' => 1])->assertOk()->assertJsonPath('data.kcal', 150);
    }
}

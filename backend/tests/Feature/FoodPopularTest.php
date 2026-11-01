<?php

namespace Tests\Feature;

use App\Models\Food;
use Illuminate\Support\Str;

class FoodPopularTest extends ApiTestCase
{
    private function food(string $name, bool $verified = true): Food
    {
        $f = Food::create(['name' => $name, 'source' => 'seed', 'verified' => $verified]);
        $f->servings()->create(['label' => '1 serving', 'kcal' => 100, 'protein_g' => 1, 'carbs_g' => 1, 'fat_g' => 1, 'is_default' => true]);

        return $f->load('servings');
    }

    private function log(Food $f): void
    {
        $this->postJson('/api/v1/logs', [
            'client_uuid' => (string) Str::uuid(),
            'food_id' => $f->id,
            'serving_id' => $f->servings[0]->id,
            'qty' => 1,
            'meal' => 'breakfast',
        ])->assertCreated();
    }

    public function test_popular_orders_by_usage_then_falls_back_to_verified_foods(): void
    {
        $u = $this->client();
        $this->as($u);

        $rare = $this->food('Zebra loaf');
        $common = $this->food('Banana');
        $fallback = $this->food('Apple');
        $this->food('Unverified thing', false);

        $this->log($rare);
        $this->log($common);
        $this->log($common);

        $res = $this->getJson('/api/v1/foods/popular?limit=2')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame($common->id, $res->json('data.0.id'));
        $this->assertSame($rare->id, $res->json('data.1.id'));
        $this->assertCount(1, $res->json('data.0.servings'));

        // With a larger limit the verified, unused food fills the remaining slot.
        $wide = $this->getJson('/api/v1/foods/popular?limit=5')->assertOk();
        $this->assertContains($fallback->id, array_column($wide->json('data'), 'id'));
        $this->assertNotContains('Unverified thing', array_column($wide->json('data'), 'name'));
    }

    public function test_popular_never_exposes_another_users_custom_food(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $custom = $this->food('Owner secret shake', false);
        $custom->update(['owner_id' => $owner->id, 'source' => 'custom']);

        $this->as($owner);
        for ($i = 0; $i < 3; $i++) {
            $this->log($custom->fresh('servings'));
        }

        $res = $this->as($other)->getJson('/api/v1/foods/popular?limit=10')->assertOk();
        $this->assertNotContains($custom->id, array_column($res->json('data'), 'id'));

        $mine = $this->as($owner)->getJson('/api/v1/foods/popular?limit=10')->assertOk();
        $this->assertContains($custom->id, array_column($mine->json('data'), 'id'));
    }
}

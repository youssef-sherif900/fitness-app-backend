<?php

namespace Tests\Feature;

use Illuminate\Support\Str;

class BodyWeightTest extends ApiTestCase
{
    public function test_weight_is_idempotent_and_returns_a_trend(): void
    {
        $u = $this->client();
        $uuid = (string) Str::uuid();

        $this->as($u)->postJson('/api/v1/body/weights', ['client_uuid' => $uuid, 'value_kg' => 80.0, 'measured_at' => '2026-10-10T07:00:00Z'])->assertCreated();
        $this->as($u)->postJson('/api/v1/body/weights', ['client_uuid' => $uuid, 'value_kg' => 80.0, 'measured_at' => '2026-10-10T07:00:00Z'])->assertOk();
        $this->as($u)->postJson('/api/v1/body/weights', ['client_uuid' => (string) Str::uuid(), 'value_kg' => 79.0, 'measured_at' => '2026-10-17T07:00:00Z'])->assertCreated();

        $this->assertDatabaseCount('weights', 2);
        $r = $this->as($u)->getJson('/api/v1/body/weights?from=2026-10-01&to=2026-10-17')->assertOk();
        $this->assertCount(2, $r->json('data.trend'));
        $this->assertLessThan(0, $r->json('data.slope_kg_per_week'));
    }

    public function test_a_client_can_correct_a_weigh_in(): void
    {
        $u = $this->client();
        $id = $this->as($u)
            ->postJson('/api/v1/body/weights', ['client_uuid' => (string) Str::uuid(), 'value_kg' => 80.0, 'measured_at' => '2026-10-10T07:00:00Z'])
            ->assertCreated()
            ->json('data.id');

        $this->as($u)
            ->patchJson("/api/v1/body/weights/{$id}", ['value_kg' => 79.4, 'measured_at' => '2026-10-11T07:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.value_kg', 79.4)
            ->assertJsonPath('data.measured_on', '2026-10-11');

        $this->as($u)->patchJson("/api/v1/body/weights/{$id}", ['value_kg' => 5])->assertUnprocessable();
    }

    public function test_other_users_cannot_edit_or_delete_a_weigh_in(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $id = $this->as($owner)
            ->postJson('/api/v1/body/weights', ['client_uuid' => (string) Str::uuid(), 'value_kg' => 80.0])
            ->assertCreated()
            ->json('data.id');

        $this->as($other)->patchJson("/api/v1/body/weights/{$id}", ['value_kg' => 70])->assertNotFound();
        $this->as($other)->deleteJson("/api/v1/body/weights/{$id}")->assertNotFound();
        $this->assertDatabaseCount('weights', 1);
    }
}

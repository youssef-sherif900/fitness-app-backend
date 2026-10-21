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
}

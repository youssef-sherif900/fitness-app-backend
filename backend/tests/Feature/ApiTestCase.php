<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PROFILE = [
        'birth_date' => '1996-01-15', 'sex' => 'male', 'height_cm' => 180, 'weight_kg' => 80,
        'activity_level' => 'moderate', 'goal' => ['type' => 'lose', 'weekly_rate_kg' => 0.5, 'target_weight_kg' => 74],
    ];

    protected function client(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function as(User $u): static
    {
        Sanctum::actingAs($u, ['access']);

        return $this;
    }

    /** Switch the Sanctum token between requests of one test (the auth guard caches the resolved user). */
    protected function withFreshToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}

<?php

namespace Tests\Feature;

class AuthTest extends ApiTestCase
{
    private function register(string $device = 'phone-1'): array
    {
        return $this->postJson('/api/v1/auth/register', [
            'email' => 'Joe@Example.com', 'password' => 'correct-horse', 'device_id' => $device,
        ])->assertCreated()->json('data');
    }

    public function test_register_returns_access_and_refresh_tokens(): void
    {
        $s = $this->register();

        $this->assertNotEmpty($s['access_token']);
        $this->assertNotEmpty($s['refresh_token']);
        $this->assertDatabaseHas('users', ['email' => 'joe@example.com']);
    }

    public function test_access_token_works_and_refresh_token_is_rejected_for_normal_routes(): void
    {
        $s = $this->register();

        $this->withFreshToken($s['access_token'])->getJson('/api/v1/me')->assertOk();
        $this->withFreshToken($s['refresh_token'])->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_refresh_rotates_tokens_and_revokes_the_old_pair(): void
    {
        $s = $this->register();

        $new = $this->withFreshToken($s['refresh_token'])->postJson('/api/v1/auth/refresh')->assertOk()->json('data');

        $this->withFreshToken($new['access_token'])->getJson('/api/v1/me')->assertOk();
        $this->withFreshToken($s['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_per_device_revoke(): void
    {
        $a = $this->register('phone-1');
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'joe@example.com', 'password' => 'correct-horse', 'device_id' => 'tablet'])->json('data');

        $this->withFreshToken($a['access_token'])->deleteJson('/api/v1/auth/sessions/tablet')->assertNoContent();

        $this->withFreshToken($b['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withFreshToken($a['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_bad_credentials_and_error_shape(): void
    {
        $this->register();

        $this->postJson('/api/v1/auth/login', ['email' => 'joe@example.com', 'password' => 'nope', 'device_id' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email']]]);
    }

    public function test_unauthenticated_requests_are_rejected_with_the_standard_shape(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson('/api/v1/logs?date=2026-10-17')->assertUnauthorized();
    }

    public function test_account_deletion_signs_out_and_soft_deletes(): void
    {
        $s = $this->register();

        $this->withFreshToken($s['access_token'])->deleteJson('/api/v1/me')->assertStatus(202)->assertJsonPath('data.purge_after_days', 30);

        $this->assertSoftDeleted('users', ['email' => 'joe@example.com']);
        $this->withFreshToken($s['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Target;

class ProfileTargetsTest extends ApiTestCase
{
    public function test_profile_creates_the_calculated_target(): void
    {
        // birth_date is chosen so that age is 30 on the test date; adjust if the clock moves past the birthday window.
        $u = $this->client();
        $birth = now()->subYears(30)->subDays(10)->toDateString();

        $this->as($u)->putJson('/api/v1/me/profile', ['birth_date' => $birth] + self::PROFILE)
            ->assertOk()
            ->assertJsonPath('data.target.kcal', 2209)
            ->assertJsonPath('data.target.protein_g', 144)
            ->assertJsonPath('data.target.source', 'auto');
    }

    public function test_guardrail_rejects_an_unsafe_rate_and_saves_nothing(): void
    {
        $u = $this->client();
        $payload = array_replace_recursive(self::PROFILE, ['goal' => ['weekly_rate_kg' => 1.5]]);

        $this->as($u)->putJson('/api/v1/me/profile', $payload)
            ->assertStatus(422)->assertJsonPath('error.code', 'weekly_loss_rate_too_high');

        $this->assertDatabaseCount('profiles', 0);
        $this->assertDatabaseCount('targets', 0);
    }

    public function test_recalculation_never_overwrites_trainer_managed_targets(): void
    {
        $u = $this->client();
        $this->as($u)->putJson('/api/v1/me/profile', self::PROFILE)->assertOk();
        Target::create(['user_id' => $u->id, 'kcal' => 2500, 'protein_g' => 180, 'carbs_g' => 250, 'fat_g' => 80,
            'source' => 'trainer', 'effective_from' => now()->toDateString()]);

        $this->as($u)->postJson('/api/v1/nutrition/targets/recalculate')
            ->assertOk()->assertJsonPath('meta.trainer_managed', true)->assertJsonPath('data.kcal', 2500);
        $this->assertSame(2, Target::where('user_id', $u->id)->count());
    }
}

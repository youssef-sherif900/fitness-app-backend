<?php
use App\Models\{Exercise, User};
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;

function bench(): Exercise
{
    return Exercise::create(['name' => 'Bench', 'primary_muscles' => ['chest'], 'movement_pattern' => 'horizontal_push', 'exercise_type' => 'weighted']);
}

it('replays duplicate set writes without duplicating', function () {
    Sanctum::actingAs($u = User::factory()->create());
    $ex = bench();
    $w = $this->postJson('/api/v1/workouts', ['client_uuid' => (string) Str::uuid()])->json('data');
    $we = $this->postJson("/api/v1/workouts/{$w['id']}/exercises", ['exercise_id' => $ex->id])->json('data');
    $body = ['client_uuid' => (string) Str::uuid(), 'workout_exercise_id' => $we['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10];

    $this->postJson("/api/v1/workouts/{$w['id']}/sets", $body)->assertCreated();
    $this->postJson("/api/v1/workouts/{$w['id']}/sets", $body)->assertOk();
    $this->postJson("/api/v1/workouts/{$w['id']}/sets", ['weight_kg' => 99] + $body)->assertStatus(409);
    expect(\App\Models\WorkoutSet::count())->toBe(1);
});

it('computes PRs once and Epley e1RM', function () {
    Sanctum::actingAs(User::factory()->create());
    $ex = bench();
    $w = $this->postJson('/api/v1/workouts', ['client_uuid' => (string) Str::uuid()])->json('data');
    $we = $this->postJson("/api/v1/workouts/{$w['id']}/exercises", ['exercise_id' => $ex->id])->json('data');
    $this->postJson("/api/v1/workouts/{$w['id']}/sets", ['client_uuid' => (string) Str::uuid(), 'workout_exercise_id' => $we['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10]);

    $a = $this->postJson("/api/v1/workouts/{$w['id']}/complete")->assertOk()->json('data');
    $this->postJson("/api/v1/workouts/{$w['id']}/complete")->assertOk();
    expect($a['new_records'])->toHaveCount(3);
    expect(\App\Models\PrEvent::count())->toBe(3);
    expect((float) \App\Models\PersonalRecord::where('record_type', 'estimated_1rm')->value('value'))->toBe(80.0); // 60*(1+10/30)
});

it('hides another user\'s custom exercise', function () {
    $other = User::factory()->create();
    $ex = Exercise::create(['owner_id' => $other->id, 'name' => 'Secret', 'primary_muscles' => ['back'], 'movement_pattern' => 'other', 'exercise_type' => 'weighted']);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/exercises/{$ex->id}")->assertNotFound();
});
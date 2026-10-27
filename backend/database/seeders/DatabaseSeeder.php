<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Models\Program;
use App\Models\User;
use App\Services\Nutrition\TargetService;
use App\Services\Training\ProgramService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Demo data (section 9): one trainer, two clients, sample foods and exercises, one conversation. Safe to run repeatedly. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $trainer = $this->user('trainer@example.com', 'Demo Trainer', ['is_trainer' => true]);
        $alice = $this->user('alice@example.com', 'Alice Demo');
        $bob = $this->user('bob@example.com', 'Bob Demo');

        $this->profile(
            $alice,
            ['birth_date' => '1994-05-12', 'sex' => 'female', 'height_cm' => 168, 'weight_kg' => 72, 'activity_level' => 'light'],
            ['type' => 'lose', 'target_weight_kg' => 65, 'weekly_rate_kg' => 0.5]
        );
        $this->profile(
            $bob,
            ['birth_date' => '1989-11-03', 'sex' => 'male', 'height_cm' => 180, 'weight_kg' => 85, 'activity_level' => 'moderate'],
            ['type' => 'maintain', 'weekly_rate_kg' => 0]
        );

        // Alice shares everything with the trainer; Bob only nutrition.
        $linkA = $this->link($trainer, $alice, 'DEMOALICE', ['nutrition', 'workouts', 'body', 'photos', 'plan_editing']);
        $this->link($trainer, $bob, 'DEMOBOB', ['nutrition']);
        DB::table('conversations')->updateOrInsert(['link_id' => $linkA], ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        $this->foods();
        $this->call([
            ExerciseSeeder::class,
            ProgramTemplateSeeder::class,
        ]);
        $this->demoProgram($alice);
    }

    /**
     * Give the demo client an active program so the Training tab has a
     * populated state as well as the empty state.
     */
    private function demoProgram(User $client): void
    {
        if (Program::where('owner_id', $client->id)->where('is_template', false)->exists()) {
            return;
        }

        $template = Program::where('is_template', true)
            ->where('name', 'Push Pull Legs — 3 Day')
            ->with('days.items')
            ->first();

        if (!$template) {
            return;
        }

        $program = Program::create([
            'owner_id' => $client->id,
            'name' => 'My Push Pull Legs',
            'description' => $template->description,
            'goal' => $template->goal,
            'experience_level' => $template->experience_level,
            'days_per_week' => $template->days_per_week,
            'status' => 'draft',
            'version' => 1,
        ]);

        foreach ($template->days as $day) {
            $copyDay = $program->days()->create([
                'name' => $day->name,
                'day_number' => $day->day_number,
            ]);

            foreach ($day->items as $item) {
                $copyDay->items()->create($item->only([
                    'exercise_id',
                    'position',
                    'sets',
                    'rep_min',
                    'rep_max',
                    'rest_seconds',
                ]));
            }
        }

        app(ProgramService::class)->publish($program);
    }

    private function user(string $email, string $name, array $extra = []): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'email_verified_at' => now()] + $extra);
    }

    private function profile(User $u, array $profile, array $goal): void
    {
        if (!$u->profile()->exists()) {
            $u->profile()->create($profile);
        }

        if (!$u->activeGoal()) {
            $u->goals()->create($goal + ['is_active' => true]);
        }

        $u->refresh();

        app(TargetService::class)->recalculate($u, 'Seeded demo target.');
    }

    private function link(User $trainer, User $client, string $code, array $scopes): string
    {
        $existing = DB::table('trainer_clients')->where('invite_code', $code)->first();
        if ($existing) {
            return $existing->id;
        }
        $id = (string) Str::uuid();
        DB::table('trainer_clients')->insert([
            'id' => $id,
            'trainer_id' => $trainer->id,
            'client_id' => $client->id,
            'invite_code' => $code,
            'status' => 'active',
            'scopes' => json_encode($scopes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function foods(): void
    {
        // name, serving label, grams, kcal, protein, carbs, fat  (approximate generic values for demo only)
        $rows = [
            ['Egg, whole, boiled', '1 large', 50, 78, 6.3, 0.6, 5.3],
            ['Chicken breast, grilled', '100 g', 100, 165, 31, 0, 3.6],
            ['White rice, cooked', '1 cup', 158, 205, 4.3, 44.5, 0.4],
            ['Oats, dry', '40 g', 40, 152, 5.3, 26.5, 2.7],
            ['Banana', '1 medium', 118, 105, 1.3, 27, 0.4],
            ['Greek yogurt, plain 2%', '170 g', 170, 120, 17, 6, 2.5],
            ['Olive oil', '1 tbsp', 13.5, 119, 0, 0, 13.5],
            ['Pita bread, white', '1 piece', 60, 165, 5.5, 33, 0.7],
            ['Foul medames', '1 cup', 250, 220, 13, 36, 3],
            ['Lentil soup', '1 bowl', 250, 180, 11, 28, 3],
        ];
        foreach ($rows as [$name, $label, $g, $kcal, $p, $c, $f]) {
            $food = Food::firstOrCreate(['name' => $name, 'owner_id' => null], ['source' => 'seed', 'verified' => true]);
            if ($food->wasRecentlyCreated) {
                $food->servings()->create(['label' => $label, 'grams' => $g, 'kcal' => $kcal, 'protein_g' => $p, 'carbs_g' => $c, 'fat_g' => $f, 'is_default' => true]);
            }
        }
        $banana = Food::where('name', 'Banana')->whereNull('owner_id')->first();
        if ($banana && !$banana->barcode) {
            $banana->update(['barcode' => '4011100000014']); // fake barcode for demos
        }
    }

}

<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Public starter programs shown on the Templates path.
 *
 * Templates are ordinary programs with is_template = true. Copying one creates
 * a draft owned by the client, which then goes through the same publish flow as
 * a program built in the wizard.
 */
class ProgramTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $trainer = User::where('email', 'trainer@example.com')->first();
        if (!$trainer) {
            return;
        }

        $exercises = Exercise::whereNull('owner_id')->pluck('id', 'name');

        foreach ($this->templates() as $template) {
            $program = Program::firstOrNew([
                'owner_id' => $trainer->id,
                'is_template' => true,
                'name' => $template['name'],
            ]);

            $program->fill([
                'description' => $template['description'],
                'goal' => $template['goal'],
                'experience_level' => $template['experience_level'],
                'days_per_week' => count($template['days']),
                'status' => 'active',
                'version' => 1,
            ])->save();

            // Rebuild the prescription so re-running the seeder stays deterministic.
            $program->days()->delete();

            foreach ($template['days'] as $dayIndex => $day) {
                $programDay = $program->days()->create([
                    'name' => $day['name'],
                    'day_number' => $dayIndex + 1,
                ]);

                foreach ($day['items'] as $itemIndex => $item) {
                    $exerciseId = $exercises[$item[0]] ?? null;
                    if (!$exerciseId) {
                        continue;
                    }

                    $programDay->items()->create([
                        'exercise_id' => $exerciseId,
                        'position' => $itemIndex + 1,
                        'sets' => $item[1],
                        'rep_min' => $item[2],
                        'rep_max' => $item[3],
                        'rest_seconds' => $item[4],
                    ]);
                }
            }
        }
    }

    private function templates(): array
    {
        return [
            [
                'name' => 'Push Pull Legs — 3 Day',
                'description' => 'A balanced three-day split for building muscle. Push, pull, then legs, with two rest days between sessions.',
                'goal' => 'hypertrophy',
                'experience_level' => 'intermediate',
                'days' => [
                    [
                        'name' => 'Push',
                        'items' => [
                            ['Barbell Bench Press', 4, 6, 10, 120],
                            ['Overhead Press', 3, 6, 10, 120],
                            ['Incline Dumbbell Press', 3, 8, 12, 90],
                            ['Lateral Raise', 3, 12, 15, 60],
                            ['Triceps Pushdown', 3, 10, 15, 60],
                            ['Overhead Triceps Extension', 3, 10, 15, 60],
                        ],
                    ],
                    [
                        'name' => 'Pull',
                        'items' => [
                            ['Deadlift', 3, 5, 8, 180],
                            ['Pull-up', 4, 6, 10, 120],
                            ['Barbell Row', 3, 8, 12, 90],
                            ['Lat Pulldown', 3, 10, 12, 90],
                            ['Face Pull', 3, 12, 15, 60],
                            ['Barbell Curl', 3, 8, 12, 60],
                        ],
                    ],
                    [
                        'name' => 'Legs',
                        'items' => [
                            ['Barbell Back Squat', 4, 6, 10, 180],
                            ['Romanian Deadlift', 3, 8, 12, 120],
                            ['Leg Press', 3, 10, 15, 90],
                            ['Lying Leg Curl', 3, 10, 15, 60],
                            ['Standing Calf Raise', 4, 12, 20, 60],
                            ['Plank', 3, 30, 60, 60],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Upper Lower — 4 Day',
                'description' => 'Four sessions alternating upper and lower body. A good step up from a three-day plan.',
                'goal' => 'hypertrophy',
                'experience_level' => 'intermediate',
                'days' => [
                    [
                        'name' => 'Upper A',
                        'items' => [
                            ['Barbell Bench Press', 4, 6, 10, 120],
                            ['Barbell Row', 4, 6, 10, 120],
                            ['Overhead Press', 3, 8, 12, 90],
                            ['Lat Pulldown', 3, 10, 12, 90],
                            ['Lateral Raise', 3, 12, 15, 60],
                            ['Barbell Curl', 3, 8, 12, 60],
                            ['Triceps Pushdown', 3, 10, 15, 60],
                        ],
                    ],
                    [
                        'name' => 'Lower A',
                        'items' => [
                            ['Barbell Back Squat', 4, 6, 10, 180],
                            ['Romanian Deadlift', 3, 8, 12, 120],
                            ['Leg Press', 3, 10, 15, 90],
                            ['Lying Leg Curl', 3, 10, 15, 60],
                            ['Standing Calf Raise', 4, 12, 20, 60],
                            ['Plank', 3, 30, 60, 60],
                        ],
                    ],
                    [
                        'name' => 'Upper B',
                        'items' => [
                            ['Incline Dumbbell Press', 4, 8, 12, 90],
                            ['Pull-up', 4, 6, 10, 120],
                            ['Seated Cable Row', 3, 8, 12, 90],
                            ['Dumbbell Shoulder Press', 3, 8, 12, 90],
                            ['Face Pull', 3, 12, 15, 60],
                            ['Hammer Curl', 3, 10, 15, 60],
                            ['Skull Crusher', 3, 10, 15, 60],
                        ],
                    ],
                    [
                        'name' => 'Lower B',
                        'items' => [
                            ['Deadlift', 3, 5, 8, 180],
                            ['Bulgarian Split Squat', 3, 8, 12, 90],
                            ['Hip Thrust', 3, 8, 12, 90],
                            ['Leg Extension', 3, 12, 15, 60],
                            ['Seated Calf Raise', 3, 12, 20, 60],
                            ['Hanging Leg Raise', 3, 8, 15, 60],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Full Body Beginner — 3 Day',
                'description' => 'Three full-body sessions with one exercise per major movement. Simple, short and easy to recover from.',
                'goal' => 'general',
                'experience_level' => 'beginner',
                'days' => [
                    [
                        'name' => 'Full Body A',
                        'items' => [
                            ['Goblet Squat', 3, 8, 12, 90],
                            ['Machine Chest Press', 3, 8, 12, 90],
                            ['Seated Cable Row', 3, 8, 12, 90],
                            ['Dumbbell Shoulder Press', 3, 10, 12, 60],
                            ['Plank', 3, 20, 40, 60],
                        ],
                    ],
                    [
                        'name' => 'Full Body B',
                        'items' => [
                            ['Leg Press', 3, 10, 15, 90],
                            ['Push-up', 3, 8, 15, 60],
                            ['Lat Pulldown', 3, 10, 12, 90],
                            ['Dumbbell Curl', 3, 10, 15, 60],
                            ['Standing Calf Raise', 3, 12, 20, 60],
                        ],
                    ],
                    [
                        'name' => 'Full Body C',
                        'items' => [
                            ['Glute Bridge', 3, 10, 15, 60],
                            ['Incline Dumbbell Press', 3, 8, 12, 90],
                            ['Dumbbell Row', 3, 8, 12, 90],
                            ['Lateral Raise', 3, 12, 15, 60],
                            ['Cable Crunch', 3, 10, 15, 60],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Push Pull Legs — 6 Day',
                'description' => 'The three-day split run twice per week with different exercise variations. Higher volume for experienced lifters.',
                'goal' => 'hypertrophy',
                'experience_level' => 'advanced',
                'days' => [
                    [
                        'name' => 'Push A',
                        'items' => [
                            ['Barbell Bench Press', 5, 5, 8, 150],
                            ['Overhead Press', 3, 6, 10, 120],
                            ['Incline Dumbbell Press', 3, 8, 12, 90],
                            ['Lateral Raise', 4, 12, 15, 60],
                            ['Triceps Pushdown', 3, 10, 15, 60],
                            ['Overhead Triceps Extension', 3, 10, 15, 60],
                        ],
                    ],
                    [
                        'name' => 'Pull A',
                        'items' => [
                            ['Deadlift', 4, 4, 6, 180],
                            ['Pull-up', 4, 6, 10, 120],
                            ['Barbell Row', 4, 8, 12, 90],
                            ['Face Pull', 3, 12, 15, 60],
                            ['Barbell Curl', 3, 8, 12, 60],
                        ],
                    ],
                    [
                        'name' => 'Legs A',
                        'items' => [
                            ['Barbell Back Squat', 5, 5, 8, 180],
                            ['Romanian Deadlift', 3, 8, 12, 120],
                            ['Leg Press', 4, 10, 15, 90],
                            ['Lying Leg Curl', 3, 10, 15, 60],
                            ['Standing Calf Raise', 4, 12, 20, 60],
                        ],
                    ],
                    [
                        'name' => 'Push B',
                        'items' => [
                            ['Close-Grip Bench Press', 4, 6, 10, 120],
                            ['Arnold Press', 3, 8, 12, 90],
                            ['Cable Crossover', 3, 12, 15, 60],
                            ['Rear Delt Fly', 3, 12, 15, 60],
                            ['Skull Crusher', 3, 10, 15, 60],
                            ['Dips', 3, 8, 12, 90],
                        ],
                    ],
                    [
                        'name' => 'Pull B',
                        'items' => [
                            ['Lat Pulldown', 4, 8, 12, 90],
                            ['Seated Cable Row', 4, 8, 12, 90],
                            ['Straight-arm Pulldown', 3, 12, 15, 60],
                            ['Face Pull', 3, 12, 15, 60],
                            ['Hammer Curl', 3, 10, 15, 60],
                            ['Cable Curl', 3, 10, 15, 60],
                        ],
                    ],
                    [
                        'name' => 'Legs B',
                        'items' => [
                            ['Front Squat', 4, 6, 10, 150],
                            ['Hip Thrust', 4, 8, 12, 90],
                            ['Bulgarian Split Squat', 3, 8, 12, 90],
                            ['Leg Extension', 3, 12, 15, 60],
                            ['Seated Calf Raise', 4, 12, 20, 60],
                            ['Hanging Leg Raise', 3, 8, 15, 60],
                        ],
                    ],
                ],
            ],
        ];
    }
}

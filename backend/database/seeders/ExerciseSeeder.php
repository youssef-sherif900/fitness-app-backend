<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

/**
 * A public exercise library that covers every muscle group used by the
 * program templates and the guided wizard.
 *
 * Safe to run repeatedly: rows are matched by name and refreshed in place.
 */
class ExerciseSeeder extends Seeder
{
    public function run(): void
    {
        // Older demo data used a shorter name for the same movement.
        $legacyBench = Exercise::whereNull('owner_id')->where('name', 'Bench Press')->first();
        if ($legacyBench && !Exercise::whereNull('owner_id')->where('name', 'Barbell Bench Press')->exists()) {
            $legacyBench->update(['name' => 'Barbell Bench Press']);
        }

        foreach ($this->rows() as $row) {
            $exercise = Exercise::whereNull('owner_id')->where('name', $row['name'])->first();

            $attrs = [
                'description' => $row['description'] ?? null,
                'primary_muscles' => $row['primary'],
                'secondary_muscles' => $row['secondary'] ?? [],
                'equipment' => $row['equipment'],
                'movement_pattern' => $row['pattern'],
                'exercise_type' => $row['type'] ?? 'weighted',
                'is_public' => true,
            ];

            if ($exercise) {
                $exercise->update($attrs);
            } else {
                Exercise::create($attrs + [
                    'owner_id' => null,
                    'name' => $row['name'],
                    'is_public' => true,
                ]);
            }
        }
    }

    private function rows(): array
    {
        return [
            // Chest
            ['name' => 'Barbell Bench Press', 'primary' => ['chest'], 'secondary' => ['triceps', 'front_deltoids'], 'equipment' => ['barbell', 'bench'], 'pattern' => 'horizontal_push', 'description' => 'Flat barbell press for chest strength.'],
            ['name' => 'Incline Dumbbell Press', 'primary' => ['chest'], 'secondary' => ['front_deltoids', 'triceps'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'horizontal_push', 'description' => 'Incline press that emphasises the upper chest.'],
            ['name' => 'Dumbbell Fly', 'primary' => ['chest'], 'secondary' => ['front_deltoids'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'isolation'],
            ['name' => 'Cable Crossover', 'primary' => ['chest'], 'secondary' => ['front_deltoids'], 'equipment' => ['cable_machine'], 'pattern' => 'isolation'],
            ['name' => 'Push-up', 'primary' => ['chest'], 'secondary' => ['triceps', 'front_deltoids'], 'equipment' => ['bodyweight'], 'pattern' => 'horizontal_push', 'type' => 'bodyweight_reps'],
            ['name' => 'Machine Chest Press', 'primary' => ['chest'], 'secondary' => ['triceps'], 'equipment' => ['machine'], 'pattern' => 'horizontal_push'],

            // Back and lats
            ['name' => 'Pull-up', 'primary' => ['lats'], 'secondary' => ['back', 'biceps'], 'equipment' => ['pullup_bar'], 'pattern' => 'vertical_pull', 'type' => 'bodyweight_reps'],
            ['name' => 'Lat Pulldown', 'primary' => ['lats'], 'secondary' => ['back', 'biceps'], 'equipment' => ['cable_machine'], 'pattern' => 'vertical_pull'],
            ['name' => 'Barbell Row', 'primary' => ['back'], 'secondary' => ['lats', 'biceps'], 'equipment' => ['barbell'], 'pattern' => 'horizontal_pull'],
            ['name' => 'Dumbbell Row', 'primary' => ['back'], 'secondary' => ['lats', 'biceps'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'horizontal_pull'],
            ['name' => 'Seated Cable Row', 'primary' => ['back'], 'secondary' => ['lats', 'biceps'], 'equipment' => ['cable_machine'], 'pattern' => 'horizontal_pull'],
            ['name' => 'Face Pull', 'primary' => ['rear_deltoids'], 'secondary' => ['traps', 'back'], 'equipment' => ['cable_machine'], 'pattern' => 'horizontal_pull'],
            ['name' => 'Straight-arm Pulldown', 'primary' => ['lats'], 'secondary' => ['back', 'triceps'], 'equipment' => ['cable_machine'], 'pattern' => 'isolation'],

            // Shoulders
            ['name' => 'Overhead Press', 'primary' => ['front_deltoids'], 'secondary' => ['triceps', 'side_deltoids'], 'equipment' => ['barbell'], 'pattern' => 'vertical_push'],
            ['name' => 'Dumbbell Shoulder Press', 'primary' => ['front_deltoids'], 'secondary' => ['triceps', 'side_deltoids'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'vertical_push'],
            ['name' => 'Lateral Raise', 'primary' => ['side_deltoids'], 'secondary' => ['traps'], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
            ['name' => 'Rear Delt Fly', 'primary' => ['rear_deltoids'], 'secondary' => ['traps', 'back'], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
            ['name' => 'Arnold Press', 'primary' => ['front_deltoids'], 'secondary' => ['side_deltoids', 'triceps'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'vertical_push'],

            // Biceps
            ['name' => 'Barbell Curl', 'primary' => ['biceps'], 'secondary' => ['forearms'], 'equipment' => ['barbell'], 'pattern' => 'isolation'],
            ['name' => 'Dumbbell Curl', 'primary' => ['biceps'], 'secondary' => ['forearms'], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
            ['name' => 'Hammer Curl', 'primary' => ['biceps'], 'secondary' => ['forearms'], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
            ['name' => 'Cable Curl', 'primary' => ['biceps'], 'secondary' => ['forearms'], 'equipment' => ['cable_machine'], 'pattern' => 'isolation'],

            // Triceps
            ['name' => 'Close-Grip Bench Press', 'primary' => ['triceps'], 'secondary' => ['chest', 'front_deltoids'], 'equipment' => ['barbell', 'bench'], 'pattern' => 'horizontal_push'],
            ['name' => 'Triceps Pushdown', 'primary' => ['triceps'], 'secondary' => [], 'equipment' => ['cable_machine'], 'pattern' => 'isolation'],
            ['name' => 'Overhead Triceps Extension', 'primary' => ['triceps'], 'secondary' => [], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
            ['name' => 'Dips', 'primary' => ['triceps'], 'secondary' => ['chest', 'front_deltoids'], 'equipment' => ['bodyweight'], 'pattern' => 'horizontal_push', 'type' => 'bodyweight_reps'],
            ['name' => 'Skull Crusher', 'primary' => ['triceps'], 'secondary' => [], 'equipment' => ['barbell', 'bench'], 'pattern' => 'isolation'],

            // Quads
            ['name' => 'Barbell Back Squat', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'hamstrings'], 'equipment' => ['barbell'], 'pattern' => 'squat', 'description' => 'The main lower-body strength lift.'],
            ['name' => 'Front Squat', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'abs'], 'equipment' => ['barbell'], 'pattern' => 'squat'],
            ['name' => 'Leg Press', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'hamstrings'], 'equipment' => ['machine'], 'pattern' => 'squat'],
            ['name' => 'Bulgarian Split Squat', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'hamstrings'], 'equipment' => ['dumbbell', 'bench'], 'pattern' => 'lunge'],
            ['name' => 'Walking Lunge', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'hamstrings'], 'equipment' => ['dumbbell'], 'pattern' => 'lunge'],
            ['name' => 'Leg Extension', 'primary' => ['quadriceps'], 'secondary' => [], 'equipment' => ['machine'], 'pattern' => 'isolation'],
            ['name' => 'Goblet Squat', 'primary' => ['quadriceps'], 'secondary' => ['glutes', 'abs'], 'equipment' => ['dumbbell', 'kettlebell'], 'pattern' => 'squat'],

            // Hamstrings and glutes
            ['name' => 'Deadlift', 'primary' => ['hamstrings'], 'secondary' => ['glutes', 'back'], 'equipment' => ['barbell'], 'pattern' => 'hinge'],
            ['name' => 'Romanian Deadlift', 'primary' => ['hamstrings'], 'secondary' => ['glutes', 'back'], 'equipment' => ['barbell'], 'pattern' => 'hinge'],
            ['name' => 'Hip Thrust', 'primary' => ['glutes'], 'secondary' => ['hamstrings'], 'equipment' => ['barbell', 'bench'], 'pattern' => 'hinge'],
            ['name' => 'Lying Leg Curl', 'primary' => ['hamstrings'], 'secondary' => ['calves'], 'equipment' => ['machine'], 'pattern' => 'isolation'],
            ['name' => 'Glute Bridge', 'primary' => ['glutes'], 'secondary' => ['hamstrings'], 'equipment' => ['bodyweight'], 'pattern' => 'hinge', 'type' => 'bodyweight_reps'],
            ['name' => 'Good Morning', 'primary' => ['hamstrings'], 'secondary' => ['glutes', 'lower_back'], 'equipment' => ['barbell'], 'pattern' => 'hinge'],
            ['name' => 'Cable Kickback', 'primary' => ['glutes'], 'secondary' => ['hamstrings'], 'equipment' => ['cable_machine'], 'pattern' => 'isolation'],

            // Calves
            ['name' => 'Standing Calf Raise', 'primary' => ['calves'], 'secondary' => [], 'equipment' => ['machine'], 'pattern' => 'isolation'],
            ['name' => 'Seated Calf Raise', 'primary' => ['calves'], 'secondary' => [], 'equipment' => ['machine'], 'pattern' => 'isolation'],

            // Core
            ['name' => 'Plank', 'primary' => ['abs'], 'secondary' => ['obliques', 'lower_back'], 'equipment' => ['bodyweight'], 'pattern' => 'core', 'type' => 'duration'],
            ['name' => 'Hanging Leg Raise', 'primary' => ['abs'], 'secondary' => ['obliques'], 'equipment' => ['pullup_bar'], 'pattern' => 'core', 'type' => 'bodyweight_reps'],
            ['name' => 'Cable Crunch', 'primary' => ['abs'], 'secondary' => ['obliques'], 'equipment' => ['cable_machine'], 'pattern' => 'core'],
            ['name' => 'Ab Wheel Rollout', 'primary' => ['abs'], 'secondary' => ['obliques'], 'equipment' => ['other'], 'pattern' => 'core', 'type' => 'bodyweight_reps'],
            ['name' => 'Russian Twist', 'primary' => ['obliques'], 'secondary' => ['abs'], 'equipment' => ['other'], 'pattern' => 'core', 'type' => 'bodyweight_reps'],

            // Forearms and carries
            ["name" => "Farmer's Carry", 'primary' => ['forearms'], 'secondary' => ['traps', 'abs'], 'equipment' => ['dumbbell'], 'pattern' => 'carry'],
            ['name' => 'Wrist Curl', 'primary' => ['forearms'], 'secondary' => [], 'equipment' => ['dumbbell'], 'pattern' => 'isolation'],
        ];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->jsonb('instructions')->nullable();
            $t->jsonb('primary_muscles')->nullable();
            $t->jsonb('secondary_muscles')->nullable();
            $t->jsonb('equipment')->nullable();
            $t->string('movement_pattern')->nullable();
            $t->string('exercise_type')->default('strength');
            $t->string('media')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestampTz('archived_at')->nullable();
            $t->timestamps();
            $t->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $t->index(['owner_id', 'name']);
            $t->index(['is_public', 'archived_at']);
        });

        Schema::create('programs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('goal')->nullable();
            $t->string('experience_level')->nullable();
            $t->unsignedSmallInteger('days_per_week')->nullable();
            $t->string('status')->default('draft');
            $t->boolean('is_template')->default(false);
            $t->unsignedInteger('published_version')->nullable();
            $t->timestampTz('archived_at')->nullable();
            $t->timestamps();
            $t->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['owner_id', 'status']);
            $t->index(['is_template', 'status']);
        });

        Schema::create('program_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('program_id');
            $t->unsignedInteger('version_number');
            $t->uuid('created_by')->nullable();
            $t->string('status')->default('draft');
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestamps();
            $t->foreign('program_id')->references('id')->on('programs')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->unique(['program_id', 'version_number']);
            $t->index(['program_id', 'status']);
        });

        Schema::create('program_days', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('program_id');
            $t->uuid('program_version_id')->nullable();
            $t->unsignedSmallInteger('day_number');
            $t->string('name')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('program_id')->references('id')->on('programs')->cascadeOnDelete();
            $t->foreign('program_version_id')->references('id')->on('program_versions')->nullOnDelete();
            $t->unique(['program_id', 'day_number']);
            $t->index(['program_version_id', 'day_number']);
        });

        Schema::create('program_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('program_day_id');
            $t->uuid('exercise_id');
            $t->unsignedSmallInteger('position');
            $t->unsignedSmallInteger('sets')->default(3);
            $t->unsignedSmallInteger('rep_min')->nullable();
            $t->unsignedSmallInteger('rep_max')->nullable();
            $t->unsignedSmallInteger('rest_seconds')->nullable();
            $t->decimal('target_rpe', 3, 1)->nullable();
            $t->decimal('weight_kg', 7, 2)->nullable();
            $t->jsonb('progression_rule')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('program_day_id')->references('id')->on('program_days')->cascadeOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->restrictOnDelete();
            $t->unique(['program_day_id', 'position']);
            $t->index('exercise_id');
        });

        Schema::create('program_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('program_id');
            $t->uuid('program_version_id')->nullable();
            $t->uuid('trainer_id');
            $t->uuid('client_id');
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('status')->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('program_id')->references('id')->on('programs')->cascadeOnDelete();
            $t->foreign('program_version_id')->references('id')->on('program_versions')->nullOnDelete();
            $t->foreign('trainer_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('client_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['client_id', 'status']);
            $t->index(['trainer_id', 'status']);
            $t->index(['program_id', 'client_id']);
        });

        Schema::create('workouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('program_id')->nullable();
            $t->uuid('program_version_id')->nullable();
            $t->uuid('program_day_id')->nullable();
            $t->uuid('program_assignment_id')->nullable();
            $t->uuid('client_uuid')->nullable();
            $t->string('status')->default('in_progress');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->date('performed_on')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletesTz();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('program_id')->references('id')->on('programs')->nullOnDelete();
            $t->foreign('program_version_id')->references('id')->on('program_versions')->nullOnDelete();
            $t->foreign('program_day_id')->references('id')->on('program_days')->nullOnDelete();
            $t->foreign('program_assignment_id')->references('id')->on('program_assignments')->nullOnDelete();
            $t->unique(['user_id', 'client_uuid']);
            $t->index(['user_id', 'performed_on']);
            $t->index(['user_id', 'status']);
        });

        Schema::create('workout_exercises', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workout_id');
            $t->uuid('exercise_id');
            $t->unsignedSmallInteger('position');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('workout_id')->references('id')->on('workouts')->cascadeOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->restrictOnDelete();
            $t->unique(['workout_id', 'position']);
            $t->index(['workout_id', 'exercise_id']);
        });

        Schema::create('workout_sets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workout_id');
            $t->uuid('workout_exercise_id')->nullable();
            $t->uuid('user_id');
            $t->uuid('exercise_id')->nullable();
            $t->uuid('client_uuid')->nullable();
            $t->unsignedSmallInteger('set_number');
            $t->decimal('weight_kg', 7, 2)->nullable();
            $t->unsignedSmallInteger('reps')->nullable();
            $t->decimal('rpe', 3, 1)->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedInteger('distance_meters')->nullable();
            $t->boolean('is_warmup')->default(false);
            $t->timestampTz('completed_at')->nullable();
            $t->timestamps();
            $t->foreign('workout_id')->references('id')->on('workouts')->cascadeOnDelete();
            $t->foreign('workout_exercise_id')->references('id')->on('workout_exercises')->nullOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->nullOnDelete();
            $t->unique(['workout_id', 'client_uuid']);
            $t->index(['workout_id', 'set_number']);
            $t->index(['user_id', 'exercise_id', 'completed_at']);
        });

        Schema::create('personal_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('exercise_id');
            $t->string('record_type')->default('estimated_1rm');
            $t->decimal('value', 10, 2);
            $t->uuid('workout_id')->nullable();
            $t->uuid('workout_set_id')->nullable();
            $t->timestampTz('achieved_at');
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->cascadeOnDelete();
            $t->foreign('workout_id')->references('id')->on('workouts')->nullOnDelete();
            $t->foreign('workout_set_id')->references('id')->on('workout_sets')->nullOnDelete();
            $t->index(['user_id', 'exercise_id', 'record_type']);
            $t->index(['user_id', 'achieved_at']);
        });

        Schema::create('pr_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('exercise_id');
            $t->uuid('personal_record_id')->nullable();
            $t->uuid('workout_id')->nullable();
            $t->uuid('workout_set_id')->nullable();
            $t->string('record_type');
            $t->decimal('previous_value', 10, 2)->nullable();
            $t->decimal('new_value', 10, 2);
            $t->timestampTz('occurred_at');
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->cascadeOnDelete();
            $t->foreign('personal_record_id')->references('id')->on('personal_records')->nullOnDelete();
            $t->foreign('workout_id')->references('id')->on('workouts')->nullOnDelete();
            $t->foreign('workout_set_id')->references('id')->on('workout_sets')->nullOnDelete();
            $t->index(['user_id', 'occurred_at']);
            $t->index(['user_id', 'exercise_id', 'record_type']);
        });

        Schema::create('progression_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('program_assignment_id')->nullable();
            $t->uuid('program_item_id')->nullable();
            $t->uuid('exercise_id')->nullable();
            $t->uuid('triggered_by_workout_id')->nullable();
            $t->string('change_type');
            $t->jsonb('before_values')->nullable();
            $t->jsonb('after_values')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('changed_at');
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('program_assignment_id')->references('id')->on('program_assignments')->nullOnDelete();
            $t->foreign('program_item_id')->references('id')->on('program_items')->nullOnDelete();
            $t->foreign('exercise_id')->references('id')->on('exercises')->nullOnDelete();
            $t->foreign('triggered_by_workout_id')->references('id')->on('workouts')->nullOnDelete();
            $t->index(['user_id', 'changed_at']);
            $t->index(['program_assignment_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'progression_changes',
            'pr_events',
            'personal_records',
            'workout_sets',
            'workout_exercises',
            'workouts',
            'program_assignments',
            'program_items',
            'program_days',
            'program_versions',
            'programs',
            'exercises'
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

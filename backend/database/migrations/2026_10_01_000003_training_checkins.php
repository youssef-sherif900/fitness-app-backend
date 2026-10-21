<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('owner_id')->nullable()->constrained('users')->cascadeOnDelete(); // null = global
            $t->string('name');
            $t->json('muscles');                 // ["chest","triceps"]
            $t->string('equipment', 32)->nullable();
            $t->uuid('media_id')->nullable();
            $t->timestamps();
            $t->index('name');
        });

        Schema::create('programs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 12)->default('draft');   // draft | active | archived
            $t->boolean('is_template')->default(false);
            $t->timestamps();
        });

        Schema::create('program_days', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('position');
            $t->string('name');
        });

        Schema::create('program_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('program_day_id')->constrained('program_days')->cascadeOnDelete();
            $t->foreignUuid('exercise_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('position');
            $t->unsignedTinyInteger('sets');
            $t->string('rep_range', 12);               // "8-12"
            $t->json('progression')->nullable();
        });

        Schema::create('workouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->foreignUuid('program_day_id')->nullable()->constrained('program_days')->nullOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->date('performed_on');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['user_id', 'client_uuid']);
            $t->index(['user_id', 'performed_on']);
        });

        Schema::create('workout_sets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workout_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->foreignUuid('exercise_id')->constrained();
            $t->unsignedSmallInteger('set_number');
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->unsignedSmallInteger('reps');
            $t->decimal('rpe', 3, 1)->nullable();
            $t->timestamps();
            $t->unique(['workout_id', 'client_uuid']);
            $t->index(['exercise_id']);
        });

        Schema::create('checkins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->date('week_start');
            $t->json('metrics');
            $t->json('proposed_changes')->nullable();
            $t->string('status', 12)->default('pending'); // pending | applied | approved | overridden | skipped
            $t->timestamps();
            $t->unique(['user_id', 'week_start']);         // jobs are idempotent per user+week
        });
    }

    public function down(): void
    {
        foreach (['checkins', 'workout_sets', 'workouts', 'program_items', 'program_days', 'programs', 'exercises'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

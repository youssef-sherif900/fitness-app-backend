<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password')->nullable();           // null for Apple/Google-only accounts
            $t->string('apple_id')->nullable()->unique();
            $t->string('google_id')->nullable()->unique();
            $t->boolean('is_client')->default(true);
            $t->boolean('is_trainer')->default(false);
            $t->boolean('is_admin')->default(false);
            $t->string('locale', 8)->default('en');
            $t->string('units', 8)->default('metric');
            $t->string('timezone', 64)->default('UTC');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        // Sanctum with UUID users: tokenable_id must be a uuid, so we ship this instead of the default migration.
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->uuidMorphs('tokenable');
            $t->text('name');                // device id, used for per-device revoke (AUTH-1)
            $t->string('token', 64)->unique(); // stored hashed
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });

        Schema::create('profiles', function (Blueprint $t) {
            $t->uuid('user_id')->primary();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->date('birth_date');
            $t->string('sex', 10);                       // male | female
            $t->decimal('height_cm', 5, 1);
            $t->decimal('weight_kg', 5, 2);
            $t->string('activity_level', 16);
            $t->json('diet_preferences')->nullable();
            $t->json('allergies')->nullable();
            $t->timestamps();
        });

        Schema::create('goals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                      // lose | maintain | gain
            $t->decimal('target_weight_kg', 5, 2)->nullable();
            $t->decimal('weekly_rate_kg', 4, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['user_id', 'is_active']);
        });

        Schema::create('targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('kcal');
            $t->unsignedSmallInteger('protein_g');
            $t->unsignedSmallInteger('carbs_g');
            $t->unsignedSmallInteger('fat_g');
            $t->string('source', 10)->default('auto'); // auto | trainer
            $t->text('reason')->nullable();
            $t->date('effective_from');
            $t->timestamps();
            $t->index(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        foreach (['targets', 'goals', 'profiles', 'failed_jobs', 'personal_access_tokens', 'password_reset_tokens', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('foods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('owner_id')->nullable()->constrained('users')->cascadeOnDelete(); // null = global
            $t->string('source', 24)->default('custom');   // seed | licensed | open | custom
            $t->boolean('verified')->default(false);
            $t->string('barcode', 32)->nullable()->index();
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::statement('CREATE INDEX foods_name_trgm ON foods USING gin (name gin_trgm_ops)');

        Schema::create('food_servings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('food_id')->constrained('foods')->cascadeOnDelete();
            $t->string('label', 64);                     // "1 slice", "100 g"
            $t->decimal('grams', 8, 2)->nullable();
            $t->decimal('kcal', 8, 2);
            $t->decimal('protein_g', 8, 2)->default(0);
            $t->decimal('carbs_g', 8, 2)->default(0);
            $t->decimal('fat_g', 8, 2)->default(0);
            $t->decimal('fiber_g', 8, 2)->nullable();
            $t->boolean('is_default')->default(false);
        });

        Schema::create('food_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('food_id')->constrained('foods')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->text('reason');
            $t->string('status', 12)->default('open');
            $t->timestamps();
        });

        Schema::create('food_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->foreignUuid('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $t->foreignUuid('serving_id')->nullable()->constrained('food_servings')->nullOnDelete();
            $t->string('name');                           // snapshot: history must not change when a food is edited
            $t->decimal('qty', 8, 3);
            $t->string('meal', 12);                       // breakfast | lunch | dinner | snack
            $t->date('logged_on');
            $t->timestamp('logged_at');
            $t->decimal('kcal', 8, 2);                    // snapshot totals = serving nutrients x qty
            $t->decimal('protein_g', 8, 2)->default(0);
            $t->decimal('carbs_g', 8, 2)->default(0);
            $t->decimal('fat_g', 8, 2)->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['user_id', 'client_uuid']);      // LOG-1 idempotency
            $t->index(['user_id', 'logged_on']);
        });

        Schema::create('saved_meals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('items');
            $t->timestamps();
        });

        Schema::create('weights', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->decimal('value_kg', 5, 2);
            $t->timestamp('measured_at');
            $t->date('measured_on');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['user_id', 'client_uuid']);
            $t->index(['user_id', 'measured_on']);
        });

        Schema::create('measurements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->string('type', 24);                       // waist, hips, chest, ...
            $t->decimal('value_cm', 6, 2);
            $t->timestamp('measured_at');
            $t->date('measured_on');
            $t->timestamps();
            $t->unique(['user_id', 'client_uuid']);
            $t->index(['user_id', 'measured_on']);
        });
    }

    public function down(): void
    {
        foreach (['measurements', 'weights', 'saved_meals', 'food_logs', 'food_reports', 'food_servings', 'foods'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

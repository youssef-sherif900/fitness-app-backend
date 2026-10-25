<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->date('checkin_date');
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('waist_cm', 6, 2)->nullable();
            $table->decimal('body_fat_percentage', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('measurements')->nullable();
            $table->jsonb('photos')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'checkin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkins');
    }
};

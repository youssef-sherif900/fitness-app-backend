<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RepDB catalog id for the seeded (global) library.
 *
 * The client maps this to a bundled illustration, and a future paid tier can
 * use it to fetch looping animations without renaming anything.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('exercises', 'slug')) {
            Schema::table('exercises', function (Blueprint $t) {
                $t->string('slug')->nullable()->after('name');
                $t->index('slug');
            });
        }

        // Backfill existing rows so images work before the seeder runs again.
        foreach (config('training.exercise_slugs', []) as $name => $slug) {
            DB::table('exercises')
                ->whereNull('owner_id')
                ->where('name', $name)
                ->whereNull('slug')
                ->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exercises', 'slug')) {
            Schema::table('exercises', function (Blueprint $t) {
                $t->dropIndex(['slug']);
                $t->dropColumn('slug');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('programs', 'version')) {
            Schema::table('programs', function (Blueprint $t) {
                $t->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('programs', 'version')) {
            Schema::table('programs', function (Blueprint $t) {
                $t->dropColumn('version');
            });
        }
    }
};

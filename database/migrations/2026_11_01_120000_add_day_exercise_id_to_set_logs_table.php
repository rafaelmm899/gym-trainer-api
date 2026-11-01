<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The prescription (`day_exercises` row) a set was logged against. Nullable:
     * free sessions and off-plan sets (logged with a bare `exercise_id`) have
     * none, and rows logged before this column existed are filled in by the
     * following data migration where that is unambiguous.
     */
    public function up(): void
    {
        Schema::table('set_logs', function (Blueprint $table) {
            $table->foreignId('day_exercise_id')->nullable()->after('exercise_id')
                ->constrained('day_exercises')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('set_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('day_exercise_id');
        });
    }
};

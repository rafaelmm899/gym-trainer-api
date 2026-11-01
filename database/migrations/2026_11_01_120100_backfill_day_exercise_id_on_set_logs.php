<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Links already-logged sets to their prescription, only when the match is
     * unambiguous: the session's planned day has exactly one `day_exercises`
     * row for the set's exercise. Free sessions, off-plan sets and days that
     * repeat an exercise stay `null`.
     */
    public function up(): void
    {
        $matches = 'SELECT d.id FROM day_exercises d
            JOIN training_sessions t ON t.cycle_day_id = d.cycle_day_id
            WHERE t.id = set_logs.session_id AND d.exercise_id = set_logs.exercise_id';

        DB::statement("UPDATE set_logs
            SET day_exercise_id = (SELECT MIN(id) FROM ({$matches}) m)
            WHERE (SELECT COUNT(*) FROM ({$matches}) m) = 1");
    }

    public function down(): void
    {
        // Irreversible data fix: the schema migration's `down()` drops the column.
    }
};

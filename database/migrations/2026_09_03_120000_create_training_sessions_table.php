<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A training day the user actually executed. `training_sessions`, not
     * `sessions`: Laravel already owns the `sessions` table for its session
     * store. A planned session references a `cycle_day`; a free (off-plan)
     * session leaves it null but always carries `routine_id`.
     */
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_day_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('analysis_state')->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('conversation_id', 36)->nullable();
            $table->timestamps();
        });

        // At most one open session per user. A partial unique index (supported
        // by both PostgreSQL and SQLite) — the schema builder has no portable
        // API for it. Backstop only: TrainingSessionOpeningService rejects a
        // second open session with a clean 409, so the normal flow never hits
        // this.
        DB::statement(
            "CREATE UNIQUE INDEX training_sessions_user_in_progress_unique ON training_sessions (user_id) WHERE status = 'in_progress'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};

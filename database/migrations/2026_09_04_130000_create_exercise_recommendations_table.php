<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The next-time target the AI analyst leaves for one exercise within one
     * routine, after analyzing a completed session. One row per
     * `(user_id, routine_id, exercise_id)` — a new analysis overwrites it, no
     * history kept.
     */
    public function up(): void
    {
        Schema::create('exercise_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            // The exercise catalogue is permanent; a recommendation never
            // cascades it away.
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            // Traceability only: which session's analysis produced this row.
            $table->foreignId('source_session_id')->nullable()->constrained('training_sessions')->nullOnDelete();
            $table->decimal('target_weight_kg', 6, 2);
            $table->unsignedSmallInteger('target_sets');
            $table->unsignedSmallInteger('target_rep_min');
            $table->unsignedSmallInteger('target_rep_max');
            $table->string('action');
            $table->text('explanation');
            $table->timestamps();

            $table->unique(['user_id', 'routine_id', 'exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_recommendations');
    }
};

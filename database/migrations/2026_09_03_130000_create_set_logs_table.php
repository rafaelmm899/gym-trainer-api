<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One executed set within a training session: the weight lifted, the reps
     * completed, an optional RPE and note. `exercise_id` points straight at the
     * catalogue (never via `day_exercises`) so a free, off-plan session records
     * too. `set_number` is the 1-based index of the set within its exercise in
     * that session, validated contiguous by the service layer.
     */
    public function up(): void
    {
        Schema::create('set_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // `session_id`, not `sessions`: the domain table is `training_sessions`
            // (Laravel owns `sessions` for its session store).
            $table->foreignId('session_id')->constrained('training_sessions')->cascadeOnDelete();
            // The exercise catalogue is permanent; a set never cascades it away.
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('set_number');
            $table->decimal('weight_kg', 6, 2);
            $table->unsignedSmallInteger('reps');
            $table->decimal('rpe', 3, 1)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'exercise_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('day_exercises', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cycle_day_id')->constrained()->cascadeOnDelete();
            // The exercise catalogue is permanent; a prescription never cascades
            // it away. constrained() leaves the DB default (RESTRICT) in place.
            $table->foreignId('exercise_id')->constrained();
            $table->unsignedSmallInteger('order');
            $table->unsignedSmallInteger('sets');
            $table->unsignedSmallInteger('rep_min');
            $table->unsignedSmallInteger('rep_max');
            $table->decimal('target_weight_kg', 6, 2)->nullable();
            $table->decimal('target_rpe', 3, 1)->nullable();
            $table->unsignedSmallInteger('rest_seconds');
            $table->text('rationale');
            $table->timestamps();

            $table->unique(['cycle_day_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_exercises');
    }
};

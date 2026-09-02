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
        Schema::create('cycle_days', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order');
            $table->string('label');
            $table->json('focus_muscle_groups');
            $table->text('rationale');
            $table->timestamps();

            $table->unique(['cycle_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cycle_days');
    }
};

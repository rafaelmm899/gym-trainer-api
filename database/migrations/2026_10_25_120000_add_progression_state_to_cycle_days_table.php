<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-day progress of a cycle N+1 that is generated one queued job per day.
     * Existing and first-cycle days default to `unchanged`.
     */
    public function up(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->string('progression_state')->default('unchanged');
        });
    }

    public function down(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->dropColumn('progression_state');
        });
    }
};

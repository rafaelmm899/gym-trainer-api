<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a recommendation is still a pending target (`active`) or was
     * already folded into a generated cycle (`applied`). Plain `string`, no
     * DB `CHECK` — matches the existing enum-storage convention; the
     * `RecommendationStatus` cast plus application-level validation enforce
     * membership.
     */
    public function up(): void
    {
        Schema::table('exercise_recommendations', function (Blueprint $table) {
            $table->string('status')->default('active');
        });
    }

    public function down(): void
    {
        Schema::table('exercise_recommendations', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

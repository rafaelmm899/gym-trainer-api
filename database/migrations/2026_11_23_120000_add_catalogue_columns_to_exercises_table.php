<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue data from the exercises dataset. Every column is nullable so the
     * previous web revision, which still inserts rows without them, keeps
     * working while migrations run ahead of the deploy.
     */
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('external_id', 8)->nullable()->unique();
            $table->string('equipment')->nullable();
            $table->string('target_muscle')->nullable();
            $table->json('secondary_muscles')->nullable();
            $table->json('instructions')->nullable();
            $table->string('media_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'equipment', 'target_muscle', 'secondary_muscles', 'instructions', 'media_url']);
        });
    }
};

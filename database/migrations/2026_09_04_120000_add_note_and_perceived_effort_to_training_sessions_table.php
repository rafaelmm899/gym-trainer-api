<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The general note and perceived-effort rating a user leaves when closing
     * a session ("Completar una sesión"). Both nullable — completing a session
     * never requires either. No `->after(...)`: Postgres ignores it, so
     * ordering is not guaranteed across the Postgres runtime and the SQLite
     * test DB.
     */
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('perceived_effort')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn(['note', 'perceived_effort']);
        });
    }
};

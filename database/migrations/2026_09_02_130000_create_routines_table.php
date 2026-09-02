<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('goal');
            $table->text('hint')->nullable();
            $table->unsignedSmallInteger('days_per_cycle')->default(5);
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // At most one active routine per user. A partial unique index (supported
        // by both PostgreSQL and SQLite) — the schema builder has no portable
        // API for it. Backstop only: RoutineCreateAction archives the incumbent
        // inside the create transaction, so the normal flow never hits this.
        DB::statement(
            "CREATE UNIQUE INDEX routines_user_id_active_unique ON routines (user_id) WHERE status = 'active'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};

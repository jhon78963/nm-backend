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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->nullable()->constrained('users');
            $table->datetime('last_modification_time')->nullable();
            $table->foreignId('last_modifier_user_id')->nullable()->constrained('users');
            $table->datetime('deletion_time')->nullable();
            $table->foreignId('deleter_user_id')->nullable()->constrained('users');
            $table->boolean('is_deleted')->default(false);

            $table->date('date'); // Fecha del registro
            $table->enum('status', ['PUNTUAL', 'TARDE', 'FALTA', 'DESCANSO', 'VACACIONES'])->default('PUNTUAL');
            $table->time('check_in_time')->nullable();
            $table->integer('delay_minutes')->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(columns: ['team_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

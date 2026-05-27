<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('team_payments', function (Blueprint $table): void {
            $table->enum('payroll_period', ['q1', 'q2'])
                ->nullable()
                ->after('date')
                ->comment('Quincena a la que aplica el movimiento (independiente de la fecha de pago)');
        });

        // Backfill: inferir quincena desde el día de la fecha existente
        DB::statement("
            UPDATE team_payments
            SET payroll_period = CASE
                WHEN EXTRACT(DAY FROM date) <= 15 THEN 'q1'
                ELSE 'q2'
            END
            WHERE payroll_period IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('team_payments', function (Blueprint $table): void {
            $table->dropColumn('payroll_period');
        });
    }
};

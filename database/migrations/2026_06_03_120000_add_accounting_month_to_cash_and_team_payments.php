<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('accounting_month', 7)
                ->nullable()
                ->after('date')
                ->comment('Mes contable YYYY-MM (independiente de la fecha de pago)');
            $table->enum('payroll_period', ['q1', 'q2'])
                ->nullable()
                ->after('accounting_month')
                ->comment('Cierre quincenal: q1=1-15, q2=16-fin');
        });

        Schema::table('team_payments', function (Blueprint $table): void {
            $table->string('accounting_month', 7)
                ->nullable()
                ->after('payroll_period')
                ->comment('Mes de nómina al que aplica YYYY-MM');
        });

        $this->backfillAccountingMonth('cash_movements');
        $this->backfillAccountingMonth('team_payments');
    }

    public function down(): void
    {
        Schema::table('team_payments', function (Blueprint $table): void {
            $table->dropColumn('accounting_month');
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropColumn(['accounting_month', 'payroll_period']);
        });
    }

    private function backfillAccountingMonth(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE {$table}
                SET accounting_month = to_char(date::timestamp, 'YYYY-MM')
                WHERE accounting_month IS NULL AND date IS NOT NULL
            ");

            return;
        }

        DB::statement("
            UPDATE {$table}
            SET accounting_month = DATE_FORMAT(date, '%Y-%m')
            WHERE accounting_month IS NULL AND date IS NOT NULL
        ");
    }
};

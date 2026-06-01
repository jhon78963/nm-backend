<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('team_payments', function (Blueprint $table): void {
            $table->string('payment_method', 32)
                ->default('CASH')
                ->after('payroll_period');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE team_payments
                SET payment_method = (
                    SELECT cm.payment_method
                    FROM cash_movements cm
                    WHERE cm.id = team_payments.cash_movement_id
                      AND cm.payment_method IS NOT NULL
                      AND cm.payment_method <> ''
                )
                WHERE cash_movement_id IS NOT NULL
            ");
        } else {
            DB::statement("
                UPDATE team_payments tp
                SET payment_method = cm.payment_method
                FROM cash_movements cm
                WHERE tp.cash_movement_id = cm.id
                  AND cm.payment_method IS NOT NULL
                  AND cm.payment_method <> ''
            ");
        }
    }

    public function down(): void
    {
        Schema::table('team_payments', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accumulated_account_settings', function (Blueprint $table): void {
            $table->decimal('initial_cash', 10, 2)->default(0)->after('digital_balance');
            $table->decimal('initial_digital', 10, 2)->default(0)->after('initial_cash');
            $table->boolean('is_initialized')->default(false)->after('initial_digital');
        });

        \Illuminate\Support\Facades\DB::table('accumulated_account_settings')->update([
            'initial_cash' => \Illuminate\Support\Facades\DB::raw('cash_balance'),
            'initial_digital' => \Illuminate\Support\Facades\DB::raw('digital_balance'),
            'is_initialized' => \Illuminate\Support\Facades\DB::raw(
                'CASE WHEN cash_balance > 0 OR digital_balance > 0 THEN true ELSE false END'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('accumulated_account_settings', function (Blueprint $table): void {
            $table->dropColumn(['initial_cash', 'initial_digital', 'is_initialized']);
        });
    }
};

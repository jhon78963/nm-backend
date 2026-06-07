<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->boolean('accumulated_balance_applied')
                ->default(false)
                ->after('purchase_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropColumn('accumulated_balance_applied');
        });
    }
};

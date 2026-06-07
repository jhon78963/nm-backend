<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accumulated_account_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_month', 7);
            $table->decimal('cash_amount', 10, 2)->default(0);
            $table->decimal('digital_amount', 10, 2)->default(0);
            $table->decimal('operational_cash_snapshot', 10, 2)->nullable();
            $table->decimal('operational_digital_snapshot', 10, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->datetime('creation_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreignId('creator_user_id')->constrained('users');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->unique('transfer_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accumulated_account_transfers');
    }
};

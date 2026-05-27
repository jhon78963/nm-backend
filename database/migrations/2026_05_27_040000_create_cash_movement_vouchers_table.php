<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_movement_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_movement_id')
                ->constrained('cash_movements')
                ->cascadeOnDelete();
            $table->string('voucher_path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        // Migrar vouchers existentes en cash_movements a la nueva tabla
        \Illuminate\Support\Facades\DB::statement("
            INSERT INTO cash_movement_vouchers (cash_movement_id, voucher_path, sort_order, created_at)
            SELECT id, voucher_path, 0, CURRENT_TIMESTAMP
            FROM cash_movements
            WHERE voucher_path IS NOT NULL AND voucher_path <> ''
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movement_vouchers');
    }
};

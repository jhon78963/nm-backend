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
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('product_size_id')->constrained('product_size');
            $table->foreignId('color_id')->nullable()->constrained('colors');
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->index(['warehouse_id', 'product_size_id', 'color_id'], 'inventory_balances_wh_ps_color_idx');
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX inventory_balances_wh_ps_color_not_null_unique
                ON inventory_balances (warehouse_id, product_size_id, color_id)
                WHERE color_id IS NOT NULL'
            );

            DB::statement(
                'CREATE UNIQUE INDEX inventory_balances_wh_ps_color_null_unique
                ON inventory_balances (warehouse_id, product_size_id)
                WHERE color_id IS NULL'
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE inventory_balances
                ADD color_id_unique_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(color_id, 0)) STORED,
                ADD UNIQUE KEY inventory_balances_wh_ps_color_unique (warehouse_id, product_size_id, color_id_unique_key)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX inventory_balances_wh_ps_color_unique
            ON inventory_balances (warehouse_id, product_size_id, COALESCE(color_id, 0))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};

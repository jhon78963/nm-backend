<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_size_id')->constrained('product_size');
            $table->foreignId('color_id')->nullable()->constrained('colors');
            $table->string('direction', 8);
            $table->unsignedInteger('quantity');
            $table->string('movement_type', 32);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('balance_after_movement');
            $table->dateTime('occurred_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');

            $table->index(['warehouse_id', 'product_size_id', 'color_id', 'occurred_at'], 'inventory_movements_wh_ps_color_occurred_idx');
            $table->index(['reference_type', 'reference_id'], 'inventory_movements_reference_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

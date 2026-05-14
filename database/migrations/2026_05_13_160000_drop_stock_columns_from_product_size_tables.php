<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nota: ejecutar solo cuando `inventory_balances` esté poblado y la lógica de negocio ya no lea estas columnas.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_size') && Schema::hasColumn('product_size', 'stock')) {
            Schema::table('product_size', function (Blueprint $table): void {
                $table->dropColumn('stock');
            });
        }

        if (Schema::hasTable('product_size_color') && Schema::hasColumn('product_size_color', 'stock')) {
            Schema::table('product_size_color', function (Blueprint $table): void {
                $table->dropColumn('stock');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_size') && ! Schema::hasColumn('product_size', 'stock')) {
            Schema::table('product_size', function (Blueprint $table): void {
                $table->integer('stock')->default(0);
            });
        }

        if (Schema::hasTable('product_size_color') && ! Schema::hasColumn('product_size_color', 'stock')) {
            Schema::table('product_size_color', function (Blueprint $table): void {
                $table->integer('stock')->default(0);
            });
        }
    }
};

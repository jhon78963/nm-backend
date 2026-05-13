<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saneo de datos corruptos + CHECK (stock >= 0) en maestro y pivote de color.
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_size') || ! Schema::hasTable('product_size_color')) {
            return;
        }

        DB::table('product_size')->where('stock', '<', 0)->update(['stock' => 0]);
        DB::table('product_size_color')->where('stock', '<', 0)->update(['stock' => 0]);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE product_size ADD CONSTRAINT product_size_stock_non_negative CHECK (stock >= 0)');
            DB::statement('ALTER TABLE product_size_color ADD CONSTRAINT product_size_color_stock_non_negative CHECK (stock >= 0)');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE product_size ADD CONSTRAINT product_size_stock_non_negative CHECK (stock >= 0)');
            DB::statement('ALTER TABLE product_size_color ADD CONSTRAINT product_size_color_stock_non_negative CHECK (stock >= 0)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_size')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE product_size DROP CONSTRAINT IF EXISTS product_size_stock_non_negative');
            DB::statement('ALTER TABLE product_size_color DROP CONSTRAINT IF EXISTS product_size_color_stock_non_negative');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE product_size DROP CONSTRAINT product_size_stock_non_negative');
            DB::statement('ALTER TABLE product_size_color DROP CONSTRAINT product_size_color_stock_non_negative');
        }
    }
};

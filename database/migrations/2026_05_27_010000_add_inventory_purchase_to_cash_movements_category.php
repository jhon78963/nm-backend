<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega INVENTORY_PURCHASE al enum/check de category en cash_movements.
     * PostgreSQL (Laravel): enum de columna = varchar + CHECK; MySQL: tipo ENUM nativo.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select("
                SELECT c.conname
                FROM pg_constraint c
                JOIN pg_class t ON c.conrelid = t.oid
                WHERE t.relname = 'cash_movements'
                AND c.contype = 'c'
                AND pg_get_constraintdef(c.oid) LIKE '%category%'
            ");
            foreach ($rows as $row) {
                DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT "'.$row->conname.'"');
            }

            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_category_check CHECK (category IN (
                'ADMINISTRATIVE','STORE','INVENTORY_PURCHASE'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN category ENUM(
                'ADMINISTRATIVE',
                'STORE',
                'INVENTORY_PURCHASE'
            ) NOT NULL DEFAULT 'STORE'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::table('cash_movements')
                ->where('category', 'INVENTORY_PURCHASE')
                ->update(['category' => 'ADMINISTRATIVE']);

            $rows = DB::select("
                SELECT c.conname
                FROM pg_constraint c
                JOIN pg_class t ON c.conrelid = t.oid
                WHERE t.relname = 'cash_movements'
                AND c.contype = 'c'
                AND pg_get_constraintdef(c.oid) LIKE '%category%'
            ");
            foreach ($rows as $row) {
                DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT "'.$row->conname.'"');
            }

            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_category_check CHECK (category IN (
                'ADMINISTRATIVE','STORE'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::table('cash_movements')
                ->where('category', 'INVENTORY_PURCHASE')
                ->update(['category' => 'ADMINISTRATIVE']);

            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN category ENUM(
                'ADMINISTRATIVE',
                'STORE'
            ) NOT NULL DEFAULT 'STORE'");
        }
    }
};

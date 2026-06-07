<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Egresos desde la Cuenta Acumulada (fondo/ahorro).
     * No forman parte de los gastos operativos del mes en curso.
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
                'ADMINISTRATIVE','STORE','INVENTORY_PURCHASE','ACCUMULATED'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN category ENUM(
                'ADMINISTRATIVE',
                'STORE',
                'INVENTORY_PURCHASE',
                'ACCUMULATED'
            ) NOT NULL DEFAULT 'STORE'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::table('cash_movements')
                ->where('category', 'ACCUMULATED')
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
                'ADMINISTRATIVE','STORE','INVENTORY_PURCHASE'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::table('cash_movements')
                ->where('category', 'ACCUMULATED')
                ->update(['category' => 'ADMINISTRATIVE']);

            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN category ENUM(
                'ADMINISTRATIVE',
                'STORE',
                'INVENTORY_PURCHASE'
            ) NOT NULL DEFAULT 'STORE'");
        }
    }
};

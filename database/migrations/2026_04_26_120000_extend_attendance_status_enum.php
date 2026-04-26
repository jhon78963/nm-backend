<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega estados RECUPERACION (días recuperados) y VALDEO (día mensual Valdeo).
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
                WHERE t.relname = 'attendances'
                AND c.contype = 'c'
                AND pg_get_constraintdef(c.oid) LIKE '%status%'
            ");
            foreach ($rows as $row) {
                $name = $row->conname;
                DB::statement('ALTER TABLE attendances DROP CONSTRAINT "'.$name.'"');
            }

            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN (
                'PUNTUAL','TARDE','FALTA','DESCANSO','VACACIONES','RECUPERACION','VALDEO'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM(
                'PUNTUAL',
                'TARDE',
                'FALTA',
                'DESCANSO',
                'VACACIONES',
                'RECUPERACION',
                'VALDEO'
            ) NOT NULL DEFAULT 'PUNTUAL'");

            return;
        }

        // sqlite u otros: sin CHECK estricto en migraciones antiguas; no-op si no aplica
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select("
                SELECT c.conname
                FROM pg_constraint c
                JOIN pg_class t ON c.conrelid = t.oid
                WHERE t.relname = 'attendances'
                AND c.contype = 'c'
                AND pg_get_constraintdef(c.oid) LIKE '%status%'
            ");
            foreach ($rows as $row) {
                DB::statement('ALTER TABLE attendances DROP CONSTRAINT "'.$row->conname.'"');
            }

            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN (
                'PUNTUAL','TARDE','FALTA','DESCANSO','VACACIONES'
            ))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM(
                'PUNTUAL',
                'TARDE',
                'FALTA',
                'DESCANSO',
                'VACACIONES'
            ) NOT NULL DEFAULT 'PUNTUAL'");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presente dentro de ventana 8:00–8:15 (no puntual).
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
                DB::statement('ALTER TABLE attendances DROP CONSTRAINT "'.$row->conname.'"');
            }

            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN (
                'PUNTUAL','TARDE','FALTA','DESCANSO','VACACIONES','RECUPERACION','VALDEO','TOLERANCIA'
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
                'VALDEO',
                'TOLERANCIA'
            ) NOT NULL DEFAULT 'PUNTUAL'");
        }
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
        }
    }
};

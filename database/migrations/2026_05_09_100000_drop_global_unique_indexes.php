<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 1 de 6 — Elimina los índices únicos globales que serán reemplazados
 * por índices compuestos (tenant_id + campo) en la migración 2026_05_09_100500.
 *
 * Tablas afectadas:
 *   - customers  → elimina unique(document_number)
 *   - users      → elimina unique(email), unique(username)
 *   - sales      → elimina unique(code)
 *   - teams      → elimina unique(dni)
 *
 * EJECUTAR ANTES del backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_document_number_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->dropUnique('users_username_unique');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_code_unique');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_dni_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique('document_number');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
            $table->unique('username');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unique('code');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unique('dni');
        });
    }
};

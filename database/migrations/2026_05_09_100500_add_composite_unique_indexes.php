<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 6 de 6 — Índices únicos compuestos (tenant_id + clave de negocio).
 *
 * Sustituye los índices únicos globales eliminados en la migración
 * 2026_05_09_100000 por índices únicos compuestos que respetan la
 * segmentación por tenant.
 *
 * Cambios:
 *  - customers:  unique(tenant_id, document_number)
 *  - users:      unique(tenant_id, email)
 *                unique(tenant_id, username)   ← username es nullable;
 *                    MySQL permite múltiples NULLs en un índice UNIQUE
 *  - sales:      unique(tenant_id, code)       ← code es nullable; ídem
 *  - teams:      unique(tenant_id, dni)
 *  - roles:      unique(tenant_id, name, guard_name)
 *                → reemplaza el unique(name, guard_name) de Spatie
 *
 * PRERREQUISITO: Migraciones 100300 y 100400 ejecutadas (tenant_id NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── customers ────────────────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'document_number'], 'customers_tenant_document_unique');
        });

        // ── users ────────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            // username nullable: MySQL trata cada NULL como distinto en UNIQUE,
            // por lo que varios usuarios sin username no colisionan.
            $table->unique(['tenant_id', 'username'], 'users_tenant_username_unique');
        });

        // ── sales ────────────────────────────────────────────────────────────
        Schema::table('sales', function (Blueprint $table) {
            // code nullable: mismo comportamiento que username
            $table->unique(['tenant_id', 'code'], 'sales_tenant_code_unique');
        });

        // ── teams ────────────────────────────────────────────────────────────
        Schema::table('teams', function (Blueprint $table) {
            $table->unique(['tenant_id', 'dni'], 'teams_tenant_dni_unique');
        });

        // ── Spatie roles ─────────────────────────────────────────────────────
        // Spatie crea unique(name, guard_name). Lo reemplazamos por el compuesto
        // para que cada tenant pueda definir roles con el mismo nombre.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_guard_name_unique');
            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_name_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_tenant_name_guard_unique');
            $table->unique(['name', 'guard_name']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_tenant_dni_unique');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_tenant_code_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_username_unique');
            $table->dropUnique('users_tenant_email_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_tenant_document_unique');
        });
    }
};

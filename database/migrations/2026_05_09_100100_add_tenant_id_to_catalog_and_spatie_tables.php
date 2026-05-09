<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 2 de 6 — Agrega tenant_id (nullable) a tablas de catálogos y Spatie.
 *
 * Tablas de catálogos afectadas:
 *   - size_types, sizes, colors, genders, payment_methods
 *
 * Tablas de Spatie afectadas:
 *   - roles        → columna tenant_id nullable (no altera el unique(name, guard_name) aún)
 *   - model_has_roles → columna tenant_id nullable + índice simple (no altera el PK compuesto)
 *
 * EJECUTAR ANTES del backfill.
 * El unique(name, guard_name) de roles se reemplaza en la migración 2026_05_09_100500.
 */
return new class extends Migration
{
    private array $catalogTables = [
        'size_types',
        'sizes',
        'colors',
        'genders',
        'payment_methods',
    ];

    public function up(): void
    {
        // ── Catálogos ─────────────────────────────────────────────────────────
        foreach ($this->catalogTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    ->onDelete('restrict');
            });
        }

        // ── Spatie: roles ─────────────────────────────────────────────────────
        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table) {
                // Se coloca después de guard_name para mantener coherencia visual
                $table->unsignedBigInteger('tenant_id')
                    ->nullable()
                    ->after('guard_name');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');
            });
        }

        // ── Spatie: model_has_roles ───────────────────────────────────────────
        // No tocamos el PK compuesto (role_id, model_id, model_type).
        // tenant_id se agrega como columna de auditoría/consulta con FK e índice.
        // El contexto de tenant se deduce desde roles.tenant_id en el backfill.
        if (Schema::hasTable('model_has_roles') && ! Schema::hasColumn('model_has_roles', 'tenant_id')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')
                    ->nullable()
                    ->after('model_id');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');

                $table->index('tenant_id', 'model_has_roles_tenant_id_index');
            });
        }
    }

    public function down(): void
    {
        // Spatie (orden inverso respeta las FK)
        if (Schema::hasTable('model_has_roles') && Schema::hasColumn('model_has_roles', 'tenant_id')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->dropIndex('model_has_roles_tenant_id_index');
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        // Catálogos en orden inverso
        foreach (array_reverse($this->catalogTables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};

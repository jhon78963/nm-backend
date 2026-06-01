<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-001: Agrega tenant_id a la tabla roles para aislar roles por tenant.
 *
 * - tenant_id NULL  → rol de sistema (Super Admin, Vendedora, etc.) — solo Super Admin puede modificarlos.
 * - tenant_id filled → rol custom del tenant — solo admins del mismo tenant pueden modificarlo.
 *
 * No se activa Spatie teams feature (evita migración invasiva en tablas pivot).
 * La restricción se aplica a nivel de controlador mediante GuardsRoleTenantScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('guard_name')
                    ->constrained('tenants')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};

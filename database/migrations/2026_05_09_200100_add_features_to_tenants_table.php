<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `features` (JSON) a la tabla `tenants`.
 *
 * Almacena un array de strings con los módulos comerciales activos del tenant.
 * Ejemplo: ["electronic_billing", "ecommerce", "multi_branch"]
 *
 * El valor por defecto es un array vacío (ningún módulo extra activo).
 * Los módulos disponibles están tipados en App\Administration\Tenant\Enums\TenantFeature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('features')
                ->default('[]')
                ->after('is_active')
                ->comment('Array JSON de módulos comerciales activos. Ver TenantFeature enum.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};

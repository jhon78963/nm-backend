<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Si una migración previa de Spatie falló a mitad (tabla permissions creada pero roles sigue siendo la legacy),
     * elimina solo las tablas parciales de Spatie para poder re-ejecutar create_permission_tables.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'guard_name')) {
            return;
        }

        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('permissions');
    }

    public function down(): void
    {
        //
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita el soporte de "Teams" de Spatie Permission usando tenant_id como clave de equipo.
 * Compatible con PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. model_has_roles ────────────────────────────────────────────────
        // Backfill: deriva tenant_id desde el rol al que apunta cada fila (PostgreSQL Syntax).
        DB::statement('
            UPDATE model_has_roles
            SET tenant_id = roles.tenant_id
            FROM roles
            WHERE model_has_roles.role_id = roles.id
              AND model_has_roles.tenant_id IS NULL
              AND roles.tenant_id IS NOT NULL
        ');

        // Elimina asignaciones que no se puedan asociar a ningún tenant.
        DB::table('model_has_roles')->whereNull('tenant_id')->delete();

        // Borra la llave primaria actual (probamos los 3 nombres posibles que da Spatie/Laravel/Postgres)
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_role_model_type_primary');
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_role_id_model_id_model_type_primary');
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_pkey');

        // Hace la columna NOT NULL
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN tenant_id SET NOT NULL');

        // Reconstruye la PK incluyendo tenant_id
        DB::statement('
            ALTER TABLE model_has_roles
            ADD PRIMARY KEY (tenant_id, role_id, model_id, model_type)
        ');

        // ── 2. model_has_permissions ──────────────────────────────────────────
        if (! Schema::hasColumn('model_has_permissions', 'tenant_id')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(); // En Postgres 'after' se ignora, se añade al final
                $table->index('tenant_id', 'model_has_permissions_tenant_id_index');
            });
        }

        // Backfill: intenta derivar tenant_id del usuario si model_type = User.
        $userModelClass = config('auth.providers.users.model', \App\Administration\User\Models\User::class);

        DB::statement("
            UPDATE model_has_permissions
            SET tenant_id = users.tenant_id
            FROM users
            WHERE model_has_permissions.model_id = users.id
              AND model_has_permissions.model_type = ?
              AND model_has_permissions.tenant_id IS NULL
        ", [$userModelClass]);

        // Elimina filas de permisos directos que quedaron sin tenant_id.
        DB::table('model_has_permissions')->whereNull('tenant_id')->delete();

        // Borra la llave primaria actual
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_permission_model_type_primary');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_permission_id_model_id_model_type_primary');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_pkey');

        // Hace la columna NOT NULL
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN tenant_id SET NOT NULL');

        // Agrega Foreign Key a tenants
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->foreign('tenant_id', 'mhp_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->onDelete('restrict');
        });

        // Reconstruye la PK incluyendo tenant_id
        DB::statement('
            ALTER TABLE model_has_permissions
            ADD PRIMARY KEY (tenant_id, permission_id, model_id, model_type)
        ');

        // Limpia la caché de permisos para que Spatie recargue con el nuevo esquema.
        app('cache')
            ->store(config('permission.cache.store') !== 'default'
                ? config('permission.cache.store')
                : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // ── model_has_permissions ─────────────────────────────────────────────
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_pkey');

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropForeign('mhp_tenant_id_foreign');
            $table->dropIndex('model_has_permissions_tenant_id_index');
            $table->dropColumn('tenant_id');
        });

        DB::statement('
            ALTER TABLE model_has_permissions
            ADD PRIMARY KEY (permission_id, model_id, model_type)
        ');

        // ── model_has_roles ───────────────────────────────────────────────────
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_pkey');

        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN tenant_id DROP NOT NULL');

        DB::statement('
            ALTER TABLE model_has_roles
            ADD PRIMARY KEY (role_id, model_id, model_type)
        ');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 4 de 6 — Candados NOT NULL.
 *
 * Aplica ALTER TABLE MODIFY COLUMN para cambiar las columnas tenant_id
 * y warehouse_id de NULL → NOT NULL en las tablas críticas del negocio.
 *
 * PRERREQUISITO OBLIGATORIO: Ejecutar `php artisan tenant:backfill` antes de
 * correr esta migración. Si quedan NULLs, PostgreSQL rechazará el ALTER.
 *
 * Estrategia por tabla:
 *  - warehouses          → tenant_id        NOT NULL
 *  - users               → tenant_id        NOT NULL  (warehouse_id puede ser NULL para Super Admins)
 *  - customers           → tenant_id        NOT NULL  (warehouse_id puede ser NULL, son clientes del Tenant)
 *  - vendors             → tenant_id        NOT NULL  (warehouse_id puede ser NULL, son proveedores del Tenant)
 *  - products            → tenant_id        NOT NULL  (warehouse_id puede ser NULL, catálogo global del Tenant)
 *  - sales               → tenant_id        NOT NULL  |  warehouse_id NOT NULL
 *  - cash_movements      → tenant_id        NOT NULL  |  warehouse_id NOT NULL
 *  - teams               → tenant_id        NOT NULL  |  warehouse_id NOT NULL
 *  - purchases           → tenant_id        NOT NULL  (warehouse_id ya era NOT NULL)
 *  - expenses / orders   → solo tenant_id   NOT NULL  (warehouse_id puede ser NULL)
 *  - Catálogos           → tenant_id        NOT NULL
 *  - Spatie roles        → tenant_id        NOT NULL
 *  - Spatie model_has_roles → tenant_id     NOT NULL
 *
 * Usamos ALTER TABLE directo (no ->change()) con sintaxis PostgreSQL:
 *   SET NOT NULL  / DROP NOT NULL
 * Esto preserva FKs y constraints existentes sin requerir doctrine/dbal.
 */
return new class extends Migration
{
    /**
     * [ tabla => columnas a hacer NOT NULL ]
     * Columnas listadas en orden de dependencia (tenant_id siempre primero).
     */
    private array $alterations = [
        // Infraestructura (Usuarios y tiendas)
        'warehouses'     => ['tenant_id'],
        'users'          => ['tenant_id'],

        // Tablas Maestras (Le pertenecen a la empresa, no a una sucursal)
        'customers'      => ['tenant_id'],
        'vendors'        => ['tenant_id'],
        'products'       => ['tenant_id'],

        // Tablas Operativas (Transacciones donde el warehouse ES obligatorio)
        'sales'          => ['tenant_id', 'warehouse_id'],
        'cash_movements' => ['tenant_id', 'warehouse_id'],
        'teams'          => ['tenant_id', 'warehouse_id'],

        // purchases: warehouse_id ya era NOT NULL desde su creación
        'purchases'      => ['tenant_id'],

        // expenses y orders: warehouse puede ser NULL (gasto administrativo / pedido sin almacén)
        'expenses'       => ['tenant_id'],
        'orders'         => ['tenant_id'],

        // Catálogos
        'size_types'     => ['tenant_id'],
        'sizes'          => ['tenant_id'],
        'colors'         => ['tenant_id'],
        'genders'        => ['tenant_id'],
        'payment_methods' => ['tenant_id'],

        // Spatie
        'roles'          => ['tenant_id'],
        'model_has_roles' => ['tenant_id'],
    ];

    public function up(): void
    {
        $this->runAlterations(nullable: false);
    }

    public function down(): void
    {
        // Invertimos el orden para respetar FKs
        $this->runAlterations(nullable: true, reverse: true);
    }

    private function runAlterations(bool $nullable, bool $reverse = false): void
    {
        $tables = $reverse
            ? array_reverse($this->alterations, preserve_keys: true)
            : $this->alterations;

        foreach ($tables as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                // Verificación de seguridad: si quedan NULLs, abortar con mensaje útil
                if (! $nullable) {
                    $nullCount = DB::table($tableName)->whereNull($column)->count();

                    if ($nullCount > 0) {
                        throw new \RuntimeException(
                            "No se puede aplicar NOT NULL en \"{$tableName}\".\"{$column}\": "
                            . "{$nullCount} registro(s) con NULL. "
                            . 'Ejecuta primero: php artisan tenant:backfill'
                        );
                    }

                    DB::statement(
                        "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$column}\" SET NOT NULL"
                    );
                } else {
                    DB::statement(
                        "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$column}\" DROP NOT NULL"
                    );
                }
            }
        }
    }
};

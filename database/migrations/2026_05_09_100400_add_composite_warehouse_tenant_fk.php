<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 5 de 6 — FK compuesta: warehouse pertenece al mismo tenant.
 *
 * Patrón "partition-by-FK":
 *   1. Se crea un unique index compuesto en warehouses(id, tenant_id).
 *   2. En cada tabla de dominio se reemplaza la FK simple warehouse_id
 *      → warehouses(id) por la FK compuesta (warehouse_id, tenant_id)
 *      → warehouses(id, tenant_id).
 *
 * Esto garantiza a nivel de base de datos que ningún registro pueda
 * apuntar a un warehouse de un tenant distinto.
 *
 * PRERREQUISITO: Migración 2026_05_09_100300 (NOT NULL) ejecutada.
 *
 * Tablas afectadas:
 *   users, customers, vendors, sales, cash_movements,
 *   products, teams, purchases
 *
 * expenses y orders se omiten intencionalmente porque warehouse_id
 * sigue siendo nullable en esas tablas.
 */
return new class extends Migration
{
    /**
     * [ tabla => nombre_FK_simple_existente ]
     * Nombres generados por Laravel siguiendo la convención {tabla}_{col}_foreign.
     */
    private array $tables = [
        'users'          => 'users_warehouse_id_foreign',
        'customers'      => 'customers_warehouse_id_foreign',
        'vendors'        => 'vendors_warehouse_id_foreign',
        'sales'          => 'sales_warehouse_id_foreign',
        'cash_movements' => 'cash_movements_warehouse_id_foreign',
        'products'       => 'products_warehouse_id_foreign',
        'teams'          => 'teams_warehouse_id_foreign',
        'purchases'      => 'purchases_warehouse_id_foreign',
    ];

    public function up(): void
    {
        // ── 1. Unique compuesto en warehouses(id, tenant_id) ─────────────────
        // Este índice es el "ancla" que MySQL requiere para que otras tablas
        // puedan referenciar ambas columnas como clave foránea compuesta.
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'warehouses_id_tenant_id_unique');
        });

        // ── 2. Reemplazar FK simple por FK compuesta en cada tabla de dominio ─
        foreach ($this->tables as $tableName => $oldFkName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $oldFkName) {
                // Eliminar FK simple anterior (warehouse_id → warehouses.id)
                $table->dropForeign($oldFkName);

                // Agregar FK compuesta (warehouse_id, tenant_id) → warehouses(id, tenant_id)
                // Nombre generado: {tabla}_warehouse_id_tenant_id_foreign
                $table->foreign(['warehouse_id', 'tenant_id'])
                    ->references(['id', 'tenant_id'])
                    ->on('warehouses')
                    ->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        // ── 1. Revertir FK compuesta → FK simple en cada tabla ───────────────
        foreach (array_reverse($this->tables, preserve_keys: true) as $tableName => $oldFkName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $oldFkName) {
                // Eliminar FK compuesta
                $table->dropForeign("{$tableName}_warehouse_id_tenant_id_foreign");

                // Restaurar FK simple
                $table->foreign('warehouse_id')
                    ->references('id')
                    ->on('warehouses')
                    ->onDelete('restrict');
            });
        }

        // ── 2. Eliminar unique compuesto de warehouses ───────────────────────
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_id_tenant_id_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASO 3 de 6 — Agrega tenant_id (nullable) a todas las tablas de dominio.
 *
 * Tablas transaccionales y maestras:
 *   customers, vendors, sales, cash_movements, expenses,
 *   orders, images, collections, products, teams, purchases
 *
 * El backfill derivará el tenant_id desde warehouse_id cuando sea posible.
 * EJECUTAR ANTES del backfill.
 */
return new class extends Migration
{
    /**
     * Orden importante: tablas sin dependencias entre sí primero.
     * purchases depende de vendors (vendor_id nullable), así que vendors va antes.
     */
    private array $tables = [
        'customers',
        'vendors',
        'sales',
        'cash_movements',
        'expenses',
        'orders',
        'images',
        'collections',
        'products',
        'teams',
        'purchases',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
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
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
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

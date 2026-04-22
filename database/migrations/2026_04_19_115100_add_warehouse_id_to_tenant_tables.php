<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users ya tiene warehouse_id. Se añade warehouse_id nullable a tablas de dominio que aún no lo tienen.
     */
    public function up(): void
    {
        $tables = [
            'sales',
            'customers',
            'vendors',
            'cash_movements',
            'expenses',
            'images',
            'collections',
            'orders',
        ];

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (Schema::hasColumn($tableName, 'warehouse_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'sales',
            'customers',
            'vendors',
            'cash_movements',
            'expenses',
            'images',
            'collections',
            'orders',
        ];

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'warehouse_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
};

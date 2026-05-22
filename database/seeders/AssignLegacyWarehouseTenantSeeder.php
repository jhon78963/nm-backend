<?php

namespace Database\Seeders;

use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asigna warehouse_id (y tenant_id en users) a registros legacy con NULL/0
 * para que el WarehouseScope los incluya al consultar con almacén activo.
 */
class AssignLegacyWarehouseTenantSeeder extends Seeder
{
    public function run(): void
    {
        $warehouseId = (int) (Warehouse::query()->find(1)?->id
            ?? Warehouse::query()->orderBy('id')->value('id')
            ?? 0);

        if ($warehouseId < 1) {
            $this->command?->warn('AssignLegacyWarehouseTenantSeeder: no hay almacén; ejecuta WarehouseSeeder primero.');

            return;
        }

        $tenantId = (int) (Warehouse::query()->find($warehouseId)?->tenant_id ?? 1);

        $warehouseTables = [
            'cash_movements',
            'expenses',
            'sales',
            'customers',
            'vendors',
            'products',
            'purchases',
            'teams',
        ];

        foreach ($warehouseTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'warehouse_id')) {
                continue;
            }

            $updated = DB::table($table)
                ->where(function ($q): void {
                    $q->whereNull('warehouse_id')->orWhere('warehouse_id', 0);
                })
                ->update(['warehouse_id' => $warehouseId]);

            if ($updated > 0) {
                $this->command?->info("{$table}: {$updated} filas → warehouse_id={$warehouseId}");
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'tenant_id')) {
            $usersTenant = DB::table('users')
                ->where(function ($q): void {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', 0);
                })
                ->update(['tenant_id' => $tenantId]);

            if ($usersTenant > 0) {
                $this->command?->info("users: {$usersTenant} filas → tenant_id={$tenantId}");
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'warehouse_id')) {
            $usersWarehouse = DB::table('users')
                ->where(function ($q): void {
                    $q->whereNull('warehouse_id')->orWhere('warehouse_id', 0);
                })
                ->update(['warehouse_id' => $warehouseId]);

            if ($usersWarehouse > 0) {
                $this->command?->info("users: {$usersWarehouse} filas → warehouse_id={$warehouseId}");
            }
        }

        $this->command?->info(
            "Legacy data asignada a warehouse_id={$warehouseId}, tenant_id={$tenantId}.",
        );
    }
}

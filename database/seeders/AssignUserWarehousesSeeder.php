<?php

namespace Database\Seeders;

use App\Administration\User\Models\User;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;

class AssignUserWarehousesSeeder extends Seeder
{
    public function run(): void
    {
        $defaultWarehouseId = (int) (Warehouse::query()->orderBy('id')->value('id') ?? 0);
        if ($defaultWarehouseId < 1) {
            $this->command?->warn('AssignUserWarehousesSeeder: no hay almacenes; ejecuta WarehouseSeeder primero.');

            return;
        }

        $defaultTenantId = (int) (Warehouse::query()->find($defaultWarehouseId)?->tenant_id ?? 1);

        User::query()
            ->where(function ($q) {
                $q->whereNull('warehouse_id')->orWhere('warehouse_id', 0);
            })
            ->update(['warehouse_id' => $defaultWarehouseId]);

        User::query()
            ->where(function ($q) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', 0);
            })
            ->update(['tenant_id' => $defaultTenantId]);

        $this->command?->info(
            "Usuarios sin almacén asignados al warehouse_id={$defaultWarehouseId}.",
        );
    }
}

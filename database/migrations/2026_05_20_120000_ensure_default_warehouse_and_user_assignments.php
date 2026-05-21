<?php

use App\Administration\Tenant\Models\Tenant;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouses') || ! Schema::hasTable('users')) {
            return;
        }

        $tenantId = Tenant::query()->orderBy('id')->value('id');
        if ($tenantId === null) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Cliente principal',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $defaultWarehouseId = Warehouse::query()->orderBy('id')->value('id');
        if ($defaultWarehouseId === null) {
            $defaultWarehouseId = DB::table('warehouses')->insertGetId([
                'name' => 'Principal',
                'tenant_id' => $tenantId,
                'is_deleted' => false,
            ]);
        } else {
            DB::table('warehouses')
                ->where('id', $defaultWarehouseId)
                ->where(function ($q) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', 0);
                })
                ->update(['tenant_id' => $tenantId]);
        }

        DB::table('users')
            ->where(function ($q) {
                $q->whereNull('warehouse_id')->orWhere('warehouse_id', 0);
            })
            ->update(['warehouse_id' => $defaultWarehouseId]);

        if (Schema::hasColumn('users', 'tenant_id')) {
            DB::table('users')
                ->where(function ($q) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', 0);
                })
                ->update(['tenant_id' => $tenantId]);
        }

        $validWarehouseIds = DB::table('warehouses')->pluck('id')->all();
        if ($validWarehouseIds !== []) {
            DB::table('users')
                ->whereNotNull('warehouse_id')
                ->whereNotIn('warehouse_id', $validWarehouseIds)
                ->update(['warehouse_id' => $defaultWarehouseId]);
        }
    }

    public function down(): void
    {
        // Datos de asignación: no revertir en producción.
    }
};

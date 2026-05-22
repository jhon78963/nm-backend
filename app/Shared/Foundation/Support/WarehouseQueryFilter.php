<?php

namespace App\Shared\Foundation\Support;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

final class WarehouseQueryFilter
{
    /**
     * El aislamiento por almacén nunca se omite (incluido Super Admin).
     */
    public static function bypassScope(): bool
    {
        return false;
    }

    public static function resolveWarehouseId(?Request $request = null): int
    {
        if (! auth()->check()) {
            return 0;
        }

        $user = auth()->user();
        $userWarehouseId = (int) ($user->warehouse_id ?? 0);
        $request ??= request();
        $explicitWarehouseId = $request !== null
            ? WarehouseIdForInventoryResolver::explicitFromRequest($request)
            : 0;

        if ($explicitWarehouseId > 0 && self::userIsSuperAdmin($user)) {
            if (WarehouseIdForInventoryResolver::userCanAccessWarehouse($explicitWarehouseId, $user)) {
                return $explicitWarehouseId;
            }

            return 0;
        }

        return $userWarehouseId;
    }

    public static function apply(EloquentBuilder|QueryBuilder $builder, string $column): void
    {
        $warehouseId = self::resolveWarehouseId();

        if ($warehouseId > 0) {
            $builder->where($column, $warehouseId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }

    private static function userIsSuperAdmin(mixed $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }
}

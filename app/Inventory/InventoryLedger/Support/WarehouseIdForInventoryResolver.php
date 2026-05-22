<?php

namespace App\Inventory\InventoryLedger\Support;

use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class WarehouseIdForInventoryResolver
{
    /**
     * Prioridad: query/body `warehouse_id` o `warehouseHeader`, cabecera `X-Warehouse-Id`, fallback al almacén del producto.
     *
     * @throws AuthorizationException
     */
    public static function resolve(Request $request, ?int $productWarehouseId = null): int
    {
        $warehouseId = self::resolveWithoutAuthorization($request, $productWarehouseId);

        if ($warehouseId > 0) {
            self::assertUserCanAccessWarehouse($warehouseId);
        }

        return $warehouseId;
    }

    public static function userCanAccessWarehouse(int $warehouseId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        if (self::actingUserIsSuperAdmin($user)) {
            return true;
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null || $warehouse->tenant_id === null) {
            return false;
        }

        if ($user === null || $user->tenant_id === null) {
            return false;
        }

        return (int) $user->tenant_id === (int) $warehouse->tenant_id;
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertUserCanAccessWarehouse(int $warehouseId, ?Authenticatable $user = null): void
    {
        if (! self::userCanAccessWarehouse($warehouseId, $user)) {
            throw new AuthorizationException('No tiene permiso para acceder a este almacén.');
        }
    }

    private static function resolveWithoutAuthorization(Request $request, ?int $productWarehouseId = null): int
    {
        $fromQuery = $request->input('warehouse_id');
        if ($fromQuery !== null && $fromQuery !== '') {
            return (int) $fromQuery;
        }

        $fromCamel = $request->input('warehouseId');
        if ($fromCamel !== null && $fromCamel !== '') {
            return (int) $fromCamel;
        }

        $header = $request->header('X-Warehouse-Id');
        if ($header !== null && $header !== '') {
            return (int) $header;
        }

        if ($productWarehouseId !== null && $productWarehouseId > 0) {
            return $productWarehouseId;
        }

        return 0;
    }

    private static function actingUserIsSuperAdmin(?Authenticatable $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }
}

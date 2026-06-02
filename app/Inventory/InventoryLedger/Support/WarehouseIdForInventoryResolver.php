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
     * Prioridad: query/body `warehouse_id` o `warehouseId`, cabecera `X-Warehouse-Id`.
     */
    public static function explicitFromRequest(?Request $request = null): int
    {
        $request ??= request();
        if ($request === null) {
            return 0;
        }

        return self::resolveWithoutAuthorization($request);
    }

    /**
     * Resuelve el almacén operativo: almacén del usuario o, solo Super Admin,
     * un almacén explícito en la petición (mismo tenant).
     *
     * @throws AuthorizationException
     */
    public static function resolve(Request $request, ?int $productWarehouseId = null): int
    {
        $explicitWarehouseId = self::explicitFromRequest($request);

        if ($explicitWarehouseId > 0) {
            self::assertUserCanAccessWarehouse($explicitWarehouseId);

            return $explicitWarehouseId;
        }

        $userWarehouseId = (int) (Auth::user()?->warehouse_id ?? 0);
        if ($userWarehouseId > 0) {
            return $userWarehouseId;
        }

        if ($productWarehouseId !== null && $productWarehouseId > 0) {
            self::assertUserCanAccessWarehouse($productWarehouseId);

            return $productWarehouseId;
        }

        return 0;
    }

    public static function userCanAccessWarehouse(int $warehouseId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        if ($user === null) {
            return false;
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null || $warehouse->tenant_id === null) {
            return false;
        }

        if ($user->tenant_id === null || (int) $user->tenant_id !== (int) $warehouse->tenant_id) {
            return false;
        }

        $userWarehouseId = (int) ($user->warehouse_id ?? 0);
        if ($userWarehouseId > 0 && $userWarehouseId === $warehouseId) {
            return true;
        }

        if (self::actingUserIsSuperAdmin($user)) {
            return true;
        }

        return false;
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

    private static function resolveWithoutAuthorization(Request $request): int
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

        return 0;
    }

    private static function actingUserIsSuperAdmin(?Authenticatable $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }
}

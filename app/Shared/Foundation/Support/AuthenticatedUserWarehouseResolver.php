<?php

namespace App\Shared\Foundation\Support;

use App\Administration\User\Models\User;
use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthenticatedUserWarehouseResolver
{
    /** Roles con contexto operativo ampliado (sin bypass de WarehouseScope). */
    public const PRIVILEGED_ROLES = ['Super Admin', 'Admin'];

    public static function userHasPrivilegedRole(?User $user): bool
    {
        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        foreach (self::PRIVILEGED_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve el almacén operativo del usuario autenticado (0 si no hay ninguno aplicable).
     */
    public static function resolve(?Sale $sale = null, ?Request $request = null): int
    {
        $user = Auth::user();
        $userWarehouseId = (int) ($user?->warehouse_id ?? 0);
        if ($userWarehouseId > 0) {
            return $userWarehouseId;
        }

        $request ??= request();
        if ($request !== null && self::userIsSuperAdmin($user)) {
            $explicitWarehouseId = WarehouseIdForInventoryResolver::explicitFromRequest($request);
            if (
                $explicitWarehouseId > 0
                && WarehouseIdForInventoryResolver::userCanAccessWarehouse($explicitWarehouseId, $user)
            ) {
                return $explicitWarehouseId;
            }
        }

        return 0;
    }

    /**
     * Almacén para consultas de inventario en POS (prioriza el del usuario operativo).
     */
    public static function resolveForPosInventory(int $productWarehouseId = 0): int
    {
        $resolved = self::resolve();
        if ($resolved > 0) {
            return $resolved;
        }

        if ($productWarehouseId > 0
            && WarehouseIdForInventoryResolver::userCanAccessWarehouse($productWarehouseId, Auth::user())) {
            return $productWarehouseId;
        }

        return 0;
    }

    public static function defaultWarehouseIdForUser(?User $user): int
    {
        $tenantId = (int) ($user?->tenant_id ?? 0);
        if ($tenantId > 0) {
            $id = Warehouse::query()
                ->where('tenant_id', $tenantId)
                ->where('is_deleted', false)
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return 0;
    }

    private static function userIsSuperAdmin(?User $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('Super Admin');
    }
}

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
    /** Roles que pueden operar sin almacén asignado en el usuario (fallback al primer almacén del tenant). */
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

        $saleWarehouseId = (int) ($sale?->warehouse_id ?? 0);
        if ($saleWarehouseId > 0 && self::userHasPrivilegedRole($user)) {
            return $saleWarehouseId;
        }

        $request ??= request();
        if ($request !== null) {
            $fromRequest = WarehouseIdForInventoryResolver::resolve($request, null);
            if ($fromRequest > 0 && self::userHasPrivilegedRole($user)) {
                return $fromRequest;
            }
        }

        if (self::userHasPrivilegedRole($user)) {
            return self::defaultWarehouseIdForUser($user);
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

        if ($productWarehouseId > 0) {
            return $productWarehouseId;
        }

        return self::defaultWarehouseIdForUser(Auth::user());
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

        $id = Warehouse::query()
            ->where('is_deleted', false)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : 0;
    }
}

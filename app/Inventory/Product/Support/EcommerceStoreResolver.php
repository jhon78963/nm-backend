<?php

namespace App\Inventory\Product\Support;

use App\Inventory\Warehouse\Models\Warehouse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EcommerceStoreResolver
{
    /**
     * Resuelve el almacén del catálogo público a partir del token `store` de la petición.
     *
     * @throws NotFoundHttpException
     */
    public static function resolveWarehouse(string $storeToken): Warehouse
    {
        $warehouse = Warehouse::query()
            ->with('tenant')
            ->where('catalog_public_token', $storeToken)
            ->where('is_deleted', false)
            ->whereHas('tenant', static fn ($query) => $query->where('is_active', true))
            ->first();

        if ($warehouse !== null) {
            return $warehouse;
        }

        $legacyToken = (string) config('ecommerce.public_store_token', '');
        $legacyWarehouseId = (int) config('ecommerce.warehouse_id', 0);

        if (
            $legacyToken !== ''
            && $legacyWarehouseId > 0
            && hash_equals($legacyToken, $storeToken)
        ) {
            $legacyWarehouse = Warehouse::query()
                ->with('tenant')
                ->whereKey($legacyWarehouseId)
                ->where('is_deleted', false)
                ->first();

            if ($legacyWarehouse !== null) {
                return $legacyWarehouse;
            }
        }

        throw new NotFoundHttpException('Tienda no encontrada o no disponible.');
    }
}

<?php

namespace App\Inventory\InventoryLedger\Support;

use Illuminate\Http\Request;

final class WarehouseIdForInventoryResolver
{
    /**
     * Prioridad: query/body `warehouse_id` o `warehouseHeader`, cabecera `X-Warehouse-Id`, fallback al almacén del producto.
     */
    public static function resolve(Request $request, ?int $productWarehouseId = null): int
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
}

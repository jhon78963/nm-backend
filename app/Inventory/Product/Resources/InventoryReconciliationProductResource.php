<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Inventory\Product\Models\Product
 */
class InventoryReconciliationProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $warehouseId = WarehouseIdForInventoryResolver::resolve(
            $request,
            $this->warehouse_id !== null ? (int) $this->warehouse_id : null,
        );

        $balanceMap = InventoryBalanceLookup::mapForProductWarehouse((int) $this->id, $warehouseId);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'genderId' => $this->gender_id,
            'warehouseId' => $this->warehouse_id,
            'status' => $this->status,
            'sizes' => $this->productSizes->map(function ($productSize) use ($balanceMap, $warehouseId): array {
                $psId = (int) $productSize->id;
                $masterKey = InventoryBalanceLookup::key($psId, null);
                $masterQty = $balanceMap[$masterKey] ?? 0;

                return [
                    'id' => $productSize->id,
                    'sizeId' => $productSize->size_id,
                    'barcode' => $productSize->barcode,
                    'inventory' => [
                        'available_quantity' => $masterQty,
                        'warehouse_id' => $warehouseId,
                    ],
                    'purchasePrice' => $productSize->purchase_price,
                    'salePrice' => $productSize->sale_price,
                    'minSalePrice' => $productSize->min_sale_price,
                    'size' => $productSize->size ? [
                        'id' => $productSize->size->id,
                        'description' => $productSize->size->description,
                    ] : null,
                    'colors' => $productSize->colors->map(function ($color) use ($balanceMap, $warehouseId, $psId): array {
                        $k = InventoryBalanceLookup::key($psId, (int) $color->id);

                        return [
                            'id' => $color->id,
                            'colorId' => $color->id,
                            'description' => $color->description,
                            'hash' => $color->hash,
                            'inventory' => [
                                'available_quantity' => $balanceMap[$k] ?? 0,
                                'warehouse_id' => $warehouseId,
                            ],
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}

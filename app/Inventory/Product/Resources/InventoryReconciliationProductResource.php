<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Services\InventoryMovementService;
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
        $inventoryMovementService = app(InventoryMovementService::class);
        $warehouseId = (int) $this->warehouse_id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'genderId' => $this->gender_id,
            'warehouseId' => $this->warehouse_id,
            'status' => $this->status,
            'sizes' => $this->productSizes->map(function ($productSize) use ($inventoryMovementService, $warehouseId): array {
                return [
                    'id' => $productSize->id,
                    'sizeId' => $productSize->size_id,
                    'barcode' => $productSize->barcode,
                    'stock' => $inventoryMovementService->getTotalByProductSize($warehouseId, (int) $productSize->id),
                    'purchasePrice' => $productSize->purchase_price,
                    'salePrice' => $productSize->sale_price,
                    'minSalePrice' => $productSize->min_sale_price,
                    'size' => $productSize->size ? [
                        'id' => $productSize->size->id,
                        'description' => $productSize->size->description,
                    ] : null,
                    'colors' => $productSize->colors->map(static function ($color) use ($inventoryMovementService, $warehouseId, $productSize): array {
                        return [
                            'id' => $color->id,
                            'colorId' => $color->id,
                            'description' => $color->description,
                            'hash' => $color->hash,
                            'stock' => $inventoryMovementService->getAvailableQuantity($warehouseId, (int) $productSize->id, (int) $color->id),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}

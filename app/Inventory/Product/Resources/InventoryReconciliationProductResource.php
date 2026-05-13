<?php

namespace App\Inventory\Product\Resources;

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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'genderId' => $this->gender_id,
            'warehouseId' => $this->warehouse_id,
            'status' => $this->status,
            'sizes' => $this->productSizes->map(function ($productSize): array {
                return [
                    'id' => $productSize->id,
                    'sizeId' => $productSize->size_id,
                    'barcode' => $productSize->barcode,
                    'stock' => $productSize->stock,
                    'purchasePrice' => $productSize->purchase_price,
                    'salePrice' => $productSize->sale_price,
                    'minSalePrice' => $productSize->min_sale_price,
                    'size' => $productSize->size ? [
                        'id' => $productSize->size->id,
                        'description' => $productSize->size->description,
                    ] : null,
                    'colors' => $productSize->colors->map(static function ($color): array {
                        return [
                            'id' => $color->id,
                            'colorId' => $color->id,
                            'description' => $color->description,
                            'hash' => $color->hash,
                            'stock' => (int) $color->pivot->stock,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}

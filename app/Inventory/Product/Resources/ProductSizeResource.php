<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use App\Inventory\Product\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Inventory\Product\Models\ProductSize
 */
class ProductSizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductSize $ps */
        $ps = $this->resource;
        $ps->loadMissing(['size', 'product', 'colors']);

        $productWarehouseId = $ps->product !== null ? (int) $ps->product->warehouse_id : null;
        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, $productWarehouseId ?: null);

        $colors = $ps->colors->map(function ($color) use ($request, $ps, $productWarehouseId): array {
            return (new ProductSizeColorResource([
                'color' => $color,
                'product_size_id' => (int) $ps->id,
                'product_warehouse_id' => $productWarehouseId,
            ]))->toArray($request);
        })->values()->all();
        $available = $colors !== []
            ? array_sum(array_map(static fn (array $color): int => (int) ($color['inventory']['available_quantity'] ?? 0), $colors))
            : InventoryBalanceLookup::quantityFor($warehouseId, (int) $ps->id, null);

        return [
            'id' => $ps->id,
            'sizeId' => $ps->size_id,
            'barcode' => $ps->barcode,
            'purchasePrice' => $ps->purchase_price,
            'salePrice' => $ps->sale_price,
            'minSalePrice' => $ps->min_sale_price,
            'inventory' => [
                'available_quantity' => $available,
                'warehouse_id' => $warehouseId,
            ],
            'size' => $ps->size ? [
                'id' => $ps->size->id,
                'description' => $ps->size->description,
            ] : null,
            'colors' => $colors,
        ];
    }
}

<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\Color\Models\Color;
use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recurso para una fila color × talla. El recurso interno es un array:
 * `['color' => Color, 'product_size_id' => int, 'product_warehouse_id' => ?int]` (product_warehouse_id opcional).
 *
 * @mixin array{color: Color, product_size_id: int, product_warehouse_id?: int|null}
 */
class ProductSizeColorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Color $color */
        $color = $this->resource['color'];
        $productSizeId = (int) $this->resource['product_size_id'];
        $productWarehouseId = isset($this->resource['product_warehouse_id'])
            ? ($this->resource['product_warehouse_id'] !== null ? (int) $this->resource['product_warehouse_id'] : null)
            : null;

        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, $productWarehouseId);
        $available = InventoryBalanceLookup::quantityFor($warehouseId, $productSizeId, (int) $color->id);

        return [
            'id' => $color->id,
            'colorId' => $color->id,
            'description' => $color->description,
            'hash' => $color->hash,
            'inventory' => [
                'available_quantity' => $available,
                'warehouse_id' => $warehouseId,
            ],
        ];
    }
}

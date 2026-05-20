<?php

namespace App\Inventory\Color\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'description' => $this->description,
            'hash' => $this->hash,
            'stock' => $this->resolveStock($request),
        ], fn ($value) => $value !== null);
    }

    private function resolveStock(Request $request): ?int
    {
        if ($this->resource->offsetExists('stock') && $this->resource->getAttribute('stock') !== null) {
            return (int) $this->resource->getAttribute('stock');
        }

        $pivot = $this->pivot ?? null;
        if ($pivot === null || ! isset($pivot->product_size_id)) {
            return null;
        }

        $productWarehouseId = $this->resource->getAttribute('product_warehouse_id') !== null
            ? (int) $this->resource->getAttribute('product_warehouse_id')
            : null;

        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, $productWarehouseId);
        if ($warehouseId < 1) {
            return null;
        }

        return InventoryBalanceLookup::quantityFor(
            $warehouseId,
            (int) $pivot->product_size_id,
            (int) $this->id,
        );
    }
}

<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $warehouseId = WarehouseIdForInventoryResolver::resolve(
            $request,
            $this->warehouse_id !== null ? (int) $this->warehouse_id : null,
        );

        $model = $this->resource;
        $totalStock = isset($model->inventory_sum_qty)
            ? (int) $model->inventory_sum_qty
            : InventoryBalanceLookup::sumQuantityForProduct((int) $this->id, $warehouseId);

        /** Referencia desde la primera fila product–talla (las precios viven en `product_size`). */
        $primaryPs = ($this->relationLoaded('productSizes') && $this->productSizes->isNotEmpty())
            ? $this->productSizes->sortBy('id')->first()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name ?? '',
            'barcode' => $this->barcode,
            'inventory' => [
                'available_quantity' => $totalStock,
                'warehouse_id' => $warehouseId,
            ],
            'purchasePrice' => $primaryPs !== null ? (float) ($primaryPs->purchase_price ?? 0) : 0,
            'salePrice' => $primaryPs !== null ? (float) ($primaryPs->sale_price ?? 0) : 0,
            'minSalePrice' => $primaryPs !== null ? (float) ($primaryPs->min_sale_price ?? 0) : 0,
            'cashDiscount' => $this->cash_discount,
            'percentageDiscount' => $this->percentage_discount,
            'description' => $this->description,
            'status' => $this->status,
            'genderId' => $this->gender_id,
            'gender' => $this->gender?->name ?? 'Sin género',
            'warehouseId' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->name ?? 'Sin almacén',
            'filter' => ! $this->sizes()->exists(),
            'sizeTypeId' => $this->sizes->pluck('size_type_id')
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}

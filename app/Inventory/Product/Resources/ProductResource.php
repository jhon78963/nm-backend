<?php

namespace App\Inventory\Product\Resources;

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
        // Calculamos el stock de forma segura
        $stock = $this->sizes_sum_stock ?? $this->sizes->sum('pivot.stock');

        return [
            'id' => $this->id,
            'name' => $this->name ?? '',
            'barcode' => $this->barcode,
            'stock' => $stock,
            'cashDiscount' => $this->cash_discount,
            'percentageDiscount' => $this->percentage_discount,
            'description' => $this->description,
            'status' => $this->status,

            // --- CORRECCIÓN AQUÍ ---
            // Usamos '?->' para evitar el error si es null
            'genderId' => $this->gender_id,
            'gender' => $this->gender?->name ?? 'Sin género', // Si es null, pondrá 'Sin género'

            'warehouseId' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->name ?? 'Sin almacén', // Lo mismo aquí
            // -----------------------

            'filter' => !$this->sizes()->exists(),
            'sizeTypeId' => $this->sizes->pluck('size_type_id')
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}

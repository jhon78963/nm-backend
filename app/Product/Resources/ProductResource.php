<?php

namespace App\Product\Resources;

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
        $stock = $this->sizes_sum_stock ?? $this->sizes->sum('pivot.stock');
        $firstSize = $this->sizes->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'stock' => $stock,
            'cashDiscount' => $this->cash_discount,
            'percentageDiscount' => $this->percentage_discount,
            'description' => $this->description,
            'status' => $this->status,
            'genderId' => $this->gender_id,
            'gender' => $this->gender->name,
            'filter' => !$this->sizes()->exists(),
            'sizeTypeId' => $firstSize?->size_type_id,
        ];
    }
}

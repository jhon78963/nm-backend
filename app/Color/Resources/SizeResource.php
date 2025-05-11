<?php

namespace App\Color\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SizeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productSizeId' => $this->productSizeId,
            'description' => "Talla: $this->description - Stock: $this->stock",
            'stock' => $this->stock,
        ];
    }
}

<?php

namespace App\Size\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SizeSelectedResource extends JsonResource
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
            'description' => $this->description,
            'barcode' => $this->barcode,
            'isExists' => $this->isExists,
            'stock' => $this->stock,
            'purchasePrice' => $this->purchasePrice,
            'salePrice' => $this->salePrice,
            'minSalePrice' => $this->minSalePrice,
        ];
    }
}

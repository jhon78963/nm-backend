<?php

namespace App\Size\Resources;

use App\Color\Resources\ColorResource;
use App\Product\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            'isExists' => $this->isExists,
            'stock' => $this->stock,
            'purchasePrice' => $this->purchasePrice,
            'salePrice' => $this->salePrice,
            'minSalePrice' => $this->minSalePrice,
        ];
    }
}

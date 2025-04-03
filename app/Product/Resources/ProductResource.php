<?php

namespace App\Product\Resources;

use App\Size\Resources\SizeResource;
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'stock' => $this->stock,
            'purchasePrice' => $this->purchase_price,
            'salePrice' => $this->sale_price,
            'minSalePrice' => $this->min_sale_price,
            'status' => $this->status,
            'genderId' => $this->gender_id,
            'gender' => $this->gender->name,
            'sizes' => SizeResource::collection($this->sizes),
        ];
    }
}

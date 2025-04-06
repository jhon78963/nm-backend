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
        $totalStock = $this->sizes->sum(function($size) {
            return $size->pivot ? $size->pivot->stock : 0;
        });

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'stock' =>  $totalStock,
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

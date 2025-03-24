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
            'Name' => $this->name,
            'purchasePrice' => $this->purchase_price,
            'wholesalePrice' => $this->wholesale_price,
            'minWholesalePrice' => $this->min_wholesale_price,
            'ratailPrice' => $this->ratail_price,
            'minRatailPrice' => $this->min_ratail_price,
            'status' => $this->status,
            'genderId' => $this->gender_id,
            'gender' => $this->gender->name,
            'sizes' => SizeResource::collection($this->sizes),
        ];
    }
}

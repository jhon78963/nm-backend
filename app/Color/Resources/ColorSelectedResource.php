<?php

namespace App\Color\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColorSelectedResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->productSizeId,
            'description' => $this->description,
            'hash' => $this->hash,
            'isExists' => $this->isExists,
            'stock' => $this->stock,
        ];
    }
}

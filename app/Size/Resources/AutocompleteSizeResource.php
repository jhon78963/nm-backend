<?php

namespace App\Size\Resources;

use App\Color\Resources\ColorResource;
use App\Product\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AutocompleteSizeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return[
            'id' => $this->id,
            'value' => $this->description,
        ];
    }
}

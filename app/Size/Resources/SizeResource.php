<?php

namespace App\Size\Resources;

use App\Color\Resources\ColorResource;
use App\Product\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        return array_filter([
            'size' => [
                'id' => $this->id,
                'value' => $this->description,
            ],
            'stock' => isset($this->pivot) ? (int) $this->pivot->stock : null,
            'price' => isset($this->pivot) ? (float) $this->pivot->price : null,
            'colors' => isset($this->pivot) ? $this->getColorsForProductSize() : null,
        ], fn($value) => $value !== null);
    }

    /**
     * Get colors filtered by `product_id` and `size_id`.
     * @return AnonymousResourceCollection
     */
    private function getColorsForProductSize(): AnonymousResourceCollection
    {
        $productSize = ProductSize::where('product_id', $this->pivot->product_id)
            ->where('size_id', $this->pivot->size_id)
            ->first();

        return ColorResource::collection($productSize->colors);
    }
}

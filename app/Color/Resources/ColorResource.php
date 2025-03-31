<?php

namespace App\Color\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'description' => $this->description,
            'stock' => isset($this->pivot) ? (int) $this->pivot->stock : null,
            'price' => isset($this->pivot) ? (float) $this->pivot->price : null,
        ], fn($value) => $value !== null);
    }
}

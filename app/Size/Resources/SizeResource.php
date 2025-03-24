<?php

namespace App\Size\Resources;

use App\Color\Resources\ColorResource;
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
        return array_filter([
            'id' => $this->id,
            'size' => $this->description,
            'stock' => isset($this->pivot) ? (float) $this->pivot->stock : null,
            'colors' => $this->pivot,
        ], fn($value): bool => $value !== null);
    }
}

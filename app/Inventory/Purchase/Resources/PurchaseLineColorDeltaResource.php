<?php

namespace App\Inventory\Purchase\Resources;

use App\Inventory\Purchase\Models\PurchaseLineColorDelta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseLineColorDelta */
class PurchaseLineColorDeltaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'colorId' => $this->color_id,
            'colorDescription' => $this->whenLoaded('color', fn () => $this->color->description),
            'quantity' => (int) $this->quantity,
        ];
    }
}

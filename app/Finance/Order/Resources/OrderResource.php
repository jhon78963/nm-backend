<?php

namespace App\Finance\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'date' => $this->reference_date?->format('d/m/Y H:i') ?? '---',
            'type' => $this->type,
            'total' => (float) $this->total_amount,
            'status' => $this->status,
        ];
    }
}

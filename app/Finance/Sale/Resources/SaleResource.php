<?php

namespace App\Finance\Sale\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'date' => $this->creation_time?->format('d/m/Y H:i') ?? '---',
            'total' => (float) $this->total_amount,
            'status' => $this->status,
            'paymentMethod' => $this->payment_method,
            'customer' => $this->customer->name ?? 'Público General',
        ];
    }
}

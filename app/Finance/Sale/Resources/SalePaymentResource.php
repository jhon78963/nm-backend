<?php

namespace App\Finance\Sale\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'date' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}

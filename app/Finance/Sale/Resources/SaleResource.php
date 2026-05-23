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
            'id'                  => $this->id,
            'code'                => $this->code,
            'date'                => $this->creation_time?->format('d/m/Y H:i') ?? '---',
            'total'               => (float) $this->total_amount,
            'status'              => $this->status,
            'paymentMethod'       => $this->payment_method,
            'customer'            => $this->whenLoaded('customer', fn () => $this->customer?->name),
            // Campos de facturación electrónica (null en ventas antiguas / TICKET_INTERNO)
            'document_type'       => $this->document_type,
            'full_invoice_number' => $this->full_invoice_number,
            'sunat_status'        => $this->sunat_status,
            'serie'               => $this->serie,
            'correlativo'         => $this->correlativo,
        ];
    }
}

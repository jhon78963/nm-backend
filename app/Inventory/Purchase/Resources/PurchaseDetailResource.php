<?php

namespace App\Inventory\Purchase\Resources;

use App\Inventory\Purchase\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Purchase */
class PurchaseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = null;
        if ($this->payload_json) {
            try {
                $payload = json_decode((string) $this->payload_json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $payload = null;
            }
        }

        return [
            'id' => $this->id,
            'supplierName' => $this->supplier_name,
            'vendorId' => $this->vendor_id,
            'documentNote' => $this->document_note,
            'registeredAt' => $this->registered_at?->format('Y-m-d'),
            'warehouseId' => $this->warehouse_id,
            'warehouseName' => $this->whenLoaded('warehouse', fn () => $this->warehouse->name),
            'currency' => $this->currency,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'totalSubtotal' => (float) $this->total_subtotal,
            'creationTime' => $this->creation_time?->format('Y-m-d H:i:s'),
            'cancelledAt' => $this->cancelled_at?->format('Y-m-d H:i:s'),
            'cancellationReason' => $this->cancellation_reason,
            'lines' => PurchaseLineResource::collection($this->whenLoaded('lines')),
            'payloadSnapshot' => $payload,
        ];
    }
}

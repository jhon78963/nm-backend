<?php

namespace App\Finance\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'originWarehouseId' => $this->origin_warehouse_id,
            'destinationWarehouseId' => $this->destination_warehouse_id,


            // --- FECHAS ---
            'date' => $this->reference_date?->format('d/m/Y') ?? '---',
            'time' => $this->reference_date?->format('H:i') ?? '--:--',
            'datetime_iso' => $this->reference_date?->toIso8601String(),

            // --- MONTOS Y ESTADO ---
            'total' => (float) $this->total_amount,
            'type' => $this->type,
            'status' => $this->status,
            'notes' => $this->notes,

            // --- DETALLES (ITEMS VENDIDOS) ---
            'items' => $this->details->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'productName' => $detail->product_name_snapshot ?? 'Producto',

                    // Descripción amigable para tickets
                    'description_full' => ($detail->product_name_snapshot ?? 'Item') .
                        ' (' . ($detail->size_name_snapshot ?? '-') . ' | ' . ($detail->color_name_snapshot ?? '-') . ')',

                    'size' => $detail->size_name_snapshot,
                    'color' => $detail->color_name_snapshot,

                    'quantity' => (int) $detail->quantity,
                    'barcode' => (float) $detail->barcode,
                    'purchasePrice' => (float) $detail->purchase_price,
                    'salePrice' => (float) $detail->sale_price,
                    'minSalePrice' => (float) $detail->min_sale_price,
                    'subtotal' => (float) $detail->subtotal,
                ];
            }),
        ];
    }
}

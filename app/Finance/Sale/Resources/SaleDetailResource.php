<?php

namespace App\Finance\Sale\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,

            // --- FECHAS ---
            'date' => $this->creation_time?->format('d/m/Y') ?? '---',
            'time' => $this->creation_time?->format('H:i') ?? '--:--',
            'datetime_iso' => $this->creation_time?->toIso8601String(),

            // --- MONTOS Y ESTADO ---
            'total' => (float) $this->total_amount,
            'status' => $this->status,
            'paymentMethod' => $this->payment_method, // Método principal (Efectivo, Mixto, etc.)

            // --- CLIENTE ---
            'customer' => [
                'id' => $this->customer_id,
                'name' => $this->customer
                    ? ($this->customer->name . ' ' . $this->customer->paternal_surname)
                    : 'Público General',
                'dni' => $this->customer?->document_number ?? '---',
            ],

            // --- NUEVO: DESGLOSE DE PAGOS ---
            // Mapeamos la relación 'payments' para enviar la lista detallada
            'payments' => $this->payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'method' => $payment->method,     // CASH, YAPE, PLIN...
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->reference, // Nro Operación si existe
                    'date' => $payment->created_at?->format('d/m/Y H:i'),
                ];
            }),

            // --- DETALLES (ITEMS VENDIDOS) ---
            'items' => $this->details->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'quantity' => (int) $detail->quantity,
                    'product_name' => $detail->product_name_snapshot ?? 'Producto',

                    // Descripción amigable para tickets
                    'description_full' => ($detail->product_name_snapshot ?? 'Item') .
                        ' (' . ($detail->size_name_snapshot ?? '-') . ' | ' . ($detail->color_name_snapshot ?? '-') . ')',

                    'size' => $detail->size_name_snapshot,
                    'color' => $detail->color_name_snapshot,

                    'unit_price' => (float) $detail->unit_price,
                    'subtotal' => (float) $detail->subtotal,
                ];
            }),
        ];
    }
}

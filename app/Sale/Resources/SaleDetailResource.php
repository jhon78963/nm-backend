<?php

namespace App\Sale\Resources;

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

            // --- FECHAS (Aquí estaba el error) ---
            // Usamos el operador ?-> para preguntar: "¿Existe created_at?"
            // Si existe, lo formatea. Si no, devuelve null o un valor por defecto.
            'date' => $this->creation_time?->format('d/m/Y') ?? '---',
            'time' => $this->creation_time?->format('H:i') ?? '--:--',
            'datetime_iso' => $this->creation_time?->toIso8601String(),

            // --- MONTOS Y ESTADO ---
            'total' => (float) $this->total_amount,
            'status' => $this->status,
            'paymentMethod' => $this->payment_method,

            // --- CLIENTE (Protegido contra nulos) ---
            'customer' => [
                'id' => $this->customer_id,
                // Validamos si existe la relación customer antes de pedir el nombre
                'name' => $this->customer
                    ? ($this->customer->name . ' ' . $this->customer->paternal_surname)
                    : 'Público General',
                'dni' => $this->customer?->document_number ?? '---',
            ],

            // --- DETALLES ---
            'items' => $this->details->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'quantity' => (int) $detail->quantity,
                    'product_name' => $detail->product_name_snapshot ?? 'Producto',
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

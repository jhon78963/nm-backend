<?php

namespace App\Finance\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Datos principales de la orden
            'reference_date'           => ['required', 'date'],
            'total'                    => ['required', 'numeric', 'min:0'],
            'origin_warehouse_id'      => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'tracking_number'          => ['nullable', 'string', 'max:255'],
            'type'                     => ['required', 'string'], // ej: 'IN', 'OUT'
            'status'                   => ['nullable', 'string'],
            'notes'                    => ['nullable', 'string'],

            // Validar que venga al menos un ítem
            'items'                    => ['required', 'array', 'min:1'],

            // Validación de cada campo dentro del array de ítems
            'items.*.product_size_id'  => ['required', 'integer'],
            'items.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'items.*.size_id'          => ['required', 'integer', 'exists:sizes,id'],
            'items.*.color_id'         => ['required', 'integer'], // Si 0 es válido, no usamos exists
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0'], // Para el historial
            'items.*.barcode'          => ['required', 'string'],
            'items.*.purchase_price'   => ['required', 'numeric', 'min:0'],
            'items.*.sale_price'       => ['required', 'numeric', 'min:0'],
            'items.*.min_sale_price'   => ['required', 'numeric', 'min:0'],
        ];
    }
}

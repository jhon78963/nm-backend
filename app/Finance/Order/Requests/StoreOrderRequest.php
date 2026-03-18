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
            'referenceDate'           => ['required', 'date'],
            'total'                    => ['required', 'numeric', 'min:0'],
            'originWarehouseId'      => ['nullable', 'integer', 'exists:warehouses,id'],
            'destinationWarehouseId' => ['required', 'integer', 'exists:warehouses,id'],
            'trackingNumber'          => ['nullable', 'string', 'max:255'],
            'type'                     => ['required', 'string'], // ej: 'IN', 'OUT'
            'status'                   => ['nullable', 'string'],
            'notes'                    => ['nullable', 'string'],

            // Validar que venga al menos un ítem
            'items'                    => ['required', 'array', 'min:1'],

            // Validación de cada campo dentro del array de ítems
            'items.*.productSizeId'  => ['required', 'integer'],
            'items.*.productId'       => ['required', 'integer', 'exists:products,id'],
            'items.*.sizeId'          => ['required', 'integer', 'exists:sizes,id'],
            'items.*.colorId'         => ['required', 'integer'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unitPrice'       => ['required', 'numeric', 'min:0'],
            'items.*.barcode'          => ['required', 'string'],
            'items.*.purchasePrice'   => ['required', 'numeric', 'min:0'],
            'items.*.salePrice'       => ['required', 'numeric', 'min:0'],
            'items.*.minSalePrice'   => ['required', 'numeric', 'min:0'],
        ];
    }
}

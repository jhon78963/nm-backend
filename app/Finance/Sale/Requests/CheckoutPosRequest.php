<?php

namespace App\Finance\Sale\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutPosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer' => 'nullable|array',
            'customer.id' => 'nullable|integer',
            // El total se recalcula en servidor; el cliente puede enviarlo solo como referencia.
            'total' => 'nullable|decimal:0,2|min:0',
            'items' => 'required|array|min:1',
            'items.*.color.product_size_id' => 'required|integer',
            'items.*.color.color_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            // El margen (precio > costo de compra) se valida en servidor; no se expone el costo al cliente.
            'items.*.unitPrice' => 'required|decimal:0,2|min:0',
            // Subtotal por línea ignorado en servidor (se deriva de cantidad × precio unitario).
            'items.*.total' => 'nullable|decimal:0,2|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|in:CASH,YAPE,CARD,TRANSFER',
            'payments.*.amount' => 'required|decimal:0,2|min:0',
            'payments.*.reference' => 'nullable|string',
        ];
    }
}

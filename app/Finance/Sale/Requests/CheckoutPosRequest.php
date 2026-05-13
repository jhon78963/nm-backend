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
            'total' => 'nullable|numeric',
            'items' => 'required|array|min:1',
            'items.*.color.product_size_id' => 'required|integer',
            'items.*.color.color_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric|min:0',
            // Subtotal por línea ignorado en servidor (se deriva de cantidad × precio unitario).
            'items.*.total' => 'nullable|numeric',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric',
            'payments.*.reference' => 'nullable|string',
        ];
    }
}

<?php

namespace App\Finance\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'creationTime' => 'nullable',
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer|exists:sale_details,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.barcode' => 'nullable|numeric|min:0',
            'items.*.purchasePrice' => 'nullable|numeric|min:0',
            'items.*.salePrice' => 'nullable|numeric|min:0',
            'items.*.minSalePrice' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ];
    }
}

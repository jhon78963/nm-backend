<?php

namespace App\Finance\Sale\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeSaleRequest extends FormRequest
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
            'returned_detail_id' => 'required|integer|exists:sale_details,id',
            'difference_amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
            'new_item.product_size_id' => 'required|integer',
            'new_item.color_id' => 'required|integer',
            'new_item.final_price' => 'required|integer',
        ];
    }
}

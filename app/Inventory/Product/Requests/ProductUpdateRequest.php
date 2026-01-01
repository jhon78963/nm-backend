<?php

namespace App\Inventory\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductUpdateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:50',
            'barcode' => 'nullable|string',
            'percentageDiscount' => 'nullable|string',
            'cashDiscount' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer',
            'purchase_price' => 'nullable',
            'sale_price' => 'nullable',
            'min_sale_price' => 'nullable',
            'status' => 'nullable|string',
            'genderId' => 'sometimes|integer',
            'warehouseId' => 'sometimes|integer',
        ];
    }
}

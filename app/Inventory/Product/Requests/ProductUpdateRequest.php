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
     * Los precios por talla se actualizan vía `products/{id}/size/{sizeId}`, no en este PATCH.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:50',
            'barcode' => 'nullable|string',
            'percentageDiscount' => 'nullable',
            'cashDiscount' => 'nullable',
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer',
            'genderId' => 'sometimes|integer',
            'warehouseId' => 'sometimes|integer',
        ];
    }
}

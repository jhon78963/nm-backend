<?php

namespace App\Product\Requests;

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
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer',
            'purchase_price' => 'sometimes',
            'wholesale_price' => 'sometimes',
            'min_wholesale_price' => 'sometimes',
            'ratail_price' => 'sometimes',
            'min_ratail_price' => 'sometimes',
            'status' => 'sometimes|string',
            'gender_id' => 'sometimes|integer',
        ];
    }
}

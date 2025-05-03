<?php

namespace App\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductAddRequest extends FormRequest
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
            'codebar' => 'required',
            'stock' => 'required|integer',
            'purchase_price' => 'required',
            'sale_price' => 'required',
            'min_sale_price' => 'required',
        ];
    }
}

<?php

namespace App\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductCreateRequest extends FormRequest
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
            'name' => 'required|string|max:50',
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer',
            'purchasePrice' => 'required',
            'wholesalePrice' => 'required',
            'minWholesalePrice' => 'required',
            'ratailPrice' => 'required',
            'minRatailPrice' => 'required',
            'status' => 'required|string',
            'genderId' => 'required|integer',
        ];
    }
}

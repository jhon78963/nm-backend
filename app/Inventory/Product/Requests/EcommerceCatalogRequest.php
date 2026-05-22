<?php

namespace App\Inventory\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EcommerceCatalogRequest extends FormRequest
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
            'store' => ['required', 'string', 'min:16', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store.required' => 'El identificador de tienda (store) es obligatorio.',
        ];
    }
}

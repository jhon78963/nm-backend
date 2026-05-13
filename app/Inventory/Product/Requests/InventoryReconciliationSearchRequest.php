<?php

namespace App\Inventory\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReconciliationSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}

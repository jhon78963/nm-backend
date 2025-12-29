<?php

namespace App\Finance\Sale\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleUpdateRequest extends FormRequest
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
            'items.*.unit_price' => 'required|numeric|min:0',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required|string', // CASH, YAPE, PLIN...
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string',
        ];
    }
}

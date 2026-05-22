<?php

namespace App\Finance\CashMovement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashflowUpdateRequest extends FormRequest
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
            'type' => 'nullable|in:INCOME,EXPENSE',
            'category' => 'nullable|in:ADMINISTRATIVE,STORE',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'date' => 'nullable|date',
            'payment_method' => 'nullable|string',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
        ];
    }
}

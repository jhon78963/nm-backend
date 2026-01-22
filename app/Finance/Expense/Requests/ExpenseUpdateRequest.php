<?php

namespace App\Finance\Expense\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseUpdateRequest extends FormRequest
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
            'expenseDate' => 'sometimes|date',
            'description' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:100',
            'amount' => 'sometimes|numeric|min:0',
            'paymentMethod' => 'sometimes|string|max:50',
            'referenceCode' => 'nullable|string|max:100',
            'userId' => 'sometimes|integer',
        ];
    }
}

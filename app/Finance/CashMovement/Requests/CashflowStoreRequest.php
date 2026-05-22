<?php

namespace App\Finance\CashMovement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashflowStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($this->input('category') === 'ADMINISTRATIVE') {
            return $user->can('cashflow.getAdminMonthlyReport');
        }

        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|in:INCOME,EXPENSE',
            'category' => 'required|in:ADMINISTRATIVE,STORE',
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string',
            'date' => 'required|date',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
            'payment_method' => 'nullable|string',
        ];
    }
}

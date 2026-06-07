<?php

namespace App\Finance\AccumulatedAccount\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccumulatedAccountSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('cashflow.getAccumulatedExpensesReport');
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cash_balance' => 'required|numeric|min:0',
            'digital_balance' => 'required|numeric|min:0',
            'tracking_start_month' => 'nullable|date_format:Y-m',
        ];
    }
}

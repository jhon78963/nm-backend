<?php

namespace App\Finance\AccumulatedAccount\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializeAccumulatedAccountSettingsRequest extends FormRequest
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
            'initial_cash' => 'required|numeric|min:0',
            'initial_digital' => 'required|numeric|min:0',
            'tracking_start_month' => 'required|date_format:Y-m',
        ];
    }
}

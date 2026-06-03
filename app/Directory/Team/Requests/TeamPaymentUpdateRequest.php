<?php

namespace App\Directory\Team\Requests;

use App\Directory\Team\Models\TeamPayment;
use Illuminate\Foundation\Http\FormRequest;

class TeamPaymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $payment = $this->route('teamPayment');

        if ($user === null || ! ($payment instanceof TeamPayment)) {
            return false;
        }

        return $user->can('update', $payment);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date'           => 'required|date',
            'payroll_period' => 'required|in:q1,q2',
            'accounting_month' => 'required|date_format:Y-m',
            'type'           => 'required|in:PAYMENT,ADVANCE,DEDUCTION',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:CASH,YAPE,CARD,TRANSFER',
            'description'    => 'nullable|string|max:255',
        ];
    }
}

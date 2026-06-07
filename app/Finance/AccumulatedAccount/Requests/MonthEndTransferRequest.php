<?php

namespace App\Finance\AccumulatedAccount\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonthEndTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cashflow.getAccumulatedExpensesReport') ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transfer_month' => 'required|date_format:Y-m',
            'cash_amount' => 'required|numeric|min:0',
            'digital_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transfer_month.required' => 'Indica el mes del cierre.',
            'transfer_month.date_format' => 'El mes debe tener formato YYYY-MM.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('transferMonth') && ! $this->has('transfer_month')) {
            $this->merge(['transfer_month' => $this->input('transferMonth')]);
        }

        if ($this->has('cashAmount') && ! $this->has('cash_amount')) {
            $this->merge(['cash_amount' => $this->input('cashAmount')]);
        }

        if ($this->has('digitalAmount') && ! $this->has('digital_amount')) {
            $this->merge(['digital_amount' => $this->input('digitalAmount')]);
        }
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $cash = (float) $this->input('cash_amount', 0);
            $digital = (float) $this->input('digital_amount', 0);

            if ($cash <= 0 && $digital <= 0) {
                $validator->errors()->add(
                    'cash_amount',
                    'Indica al menos un monto en efectivo o digital para traspasar.',
                );
            }
        });
    }
}

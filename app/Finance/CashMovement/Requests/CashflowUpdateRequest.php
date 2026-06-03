<?php

namespace App\Finance\CashMovement\Requests;

use App\Finance\CashMovement\Models\CashMovement;
use App\Shared\Foundation\Rules\ValidMagicBytes;
use Illuminate\Foundation\Http\FormRequest;

class CashflowUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        /** @var CashMovement|null $movement */
        $movement = $this->route('cashMovement');

        if ($user === null || ! ($movement instanceof CashMovement)) {
            return false;
        }

        $newCategory = $this->input('category');

        return $user->can('update', [$movement, $newCategory]);
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
            'description' => 'required|string|min:1',
            'date' => 'nullable|date',
            'accounting_month' => 'nullable|date_format:Y-m',
            'payroll_period' => 'nullable|in:q1,q2',
            'payment_method' => 'nullable|string|in:CASH,YAPE,CARD,TRANSFER',
            'images' => 'nullable|array|max:10',
            'images.*' => ['file', 'mimes:jpeg,png,jpg,webp,pdf', 'max:5120', new ValidMagicBytes(['jpeg', 'png', 'webp', 'pdf'])],
        ];
    }
}

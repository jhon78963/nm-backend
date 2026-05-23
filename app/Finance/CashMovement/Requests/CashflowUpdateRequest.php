<?php

namespace App\Finance\CashMovement\Requests;

use App\Finance\CashMovement\Models\CashMovement;
use Illuminate\Foundation\Http\FormRequest;

class CashflowUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('cashflow.update')) {
            return false;
        }

        /** @var CashMovement|null $movement */
        $movement = $this->route('cashMovement');

        if ($movement === null) {
            return false;
        }

        $isAdministrativeInDb = $movement->category === 'ADMINISTRATIVE';
        $changingToAdministrative = $this->input('category') === 'ADMINISTRATIVE';

        if ($isAdministrativeInDb || $changingToAdministrative) {
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
            'type' => 'nullable|in:INCOME,EXPENSE',
            'category' => 'nullable|in:ADMINISTRATIVE,STORE',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'required|string|min:1',
            'date' => 'nullable|date',
            'payment_method' => 'nullable|string|in:CASH,YAPE,CARD,TRANSFER',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
        ];
    }
}

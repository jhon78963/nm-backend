<?php

namespace App\Finance\CashMovement\Requests;

use App\Finance\CashMovement\Models\CashMovement;
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
            'payment_method' => 'nullable|string|in:CASH,YAPE,CARD,TRANSFER',
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
        ];
    }
}

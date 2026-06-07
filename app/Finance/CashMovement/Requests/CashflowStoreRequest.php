<?php

namespace App\Finance\CashMovement\Requests;

use App\Finance\CashMovement\Models\CashMovement;
use App\Shared\Foundation\Rules\ValidMagicBytes;
use Illuminate\Foundation\Http\FormRequest;

class CashflowStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $category = $this->input('category', CashMovement::CATEGORY_STORE);

        return $user->can('create', [CashMovement::class, $category]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|in:INCOME,EXPENSE',
            'category' => 'required|in:ADMINISTRATIVE,STORE,ACCUMULATED',
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string|min:1',
            'date' => 'required|date',
            'accounting_month' => 'nullable|date_format:Y-m',
            'payroll_period' => 'nullable|in:q1,q2',
            'images' => 'nullable|array|max:10',
            'images.*' => ['file', 'mimes:jpeg,png,jpg,webp,pdf', 'max:5120', new ValidMagicBytes(['jpeg', 'png', 'webp', 'pdf'])],
            'payment_method' => 'nullable|string|in:CASH,YAPE,CARD,TRANSFER',
        ];
    }
}

<?php

namespace App\Directory\Team\Requests;

use App\Directory\Team\Models\TeamPayment;
use Illuminate\Foundation\Http\FormRequest;

class TeamPaymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('store', TeamPayment::class);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'team_id'            => 'required|exists:teams,id',
            'type'               => 'required|in:PAYMENT,ADVANCE,DEDUCTION',
            'amount'             => 'required|numeric|min:0.1',
            'date'               => 'required|date',
            'payroll_period'     => 'required|in:q1,q2',
            'description'        => 'nullable|string',
            'sync_cash_movement' => 'nullable|boolean',
            'payment_method'     => 'required|string|in:CASH,YAPE,CARD,TRANSFER',
            'images'             => 'nullable|array|max:10',
            'images.*'           => 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ];
    }
}

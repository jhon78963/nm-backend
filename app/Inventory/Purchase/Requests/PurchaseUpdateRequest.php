<?php

namespace App\Inventory\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentNote' => 'nullable|string|max:500',
            'registeredAt' => 'nullable|date',
        ];
    }
}

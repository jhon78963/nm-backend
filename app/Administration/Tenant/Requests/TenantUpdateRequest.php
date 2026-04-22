<?php

namespace App\Administration\Tenant\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:191'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}

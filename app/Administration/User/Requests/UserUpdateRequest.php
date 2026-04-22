<?php

namespace App\Administration\User\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|max:25',
            'surname' => 'sometimes|max:25',
            'file' => 'sometimes|max:2048',
            'warehouseId' => ['sometimes', 'exists:warehouses,id'],
            'tenantId' => ['sometimes', 'exists:tenants,id'],
            'roleNames' => ['sometimes', 'array', 'min:1'],
            'roleNames.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}

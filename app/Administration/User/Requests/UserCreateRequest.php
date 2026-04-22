<?php

namespace App\Administration\User\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserCreateRequest extends FormRequest
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
            'username' => 'required|string|unique:users,username|max:25',
            'email' => 'required|email|unique:users,email|max:190',
            'name' => 'required|string|max:25',
            'surname' => 'required|string|max:25',
            'roleNames' => ['required', 'array', 'min:1'],
            'roleNames.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'tenantId' => ['required', 'exists:tenants,id'],
            'warehouseId' => ['required', 'exists:warehouses,id'],
            'file' => 'nullable|max:2048',
        ];
    }
}

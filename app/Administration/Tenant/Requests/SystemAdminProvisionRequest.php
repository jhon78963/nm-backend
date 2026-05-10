<?php

namespace App\Administration\Tenant\Requests;

use App\Administration\Tenant\Enums\TenantFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida la provisión atómica de un nuevo Tenant + Admin.
 */
class SystemAdminProvisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_name'    => ['required', 'string', 'max:100'],
            'admin_name'     => ['required', 'string', 'max:100'],
            'admin_email'    => ['required', 'email', 'max:150', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
            'features'       => ['sometimes', 'array'],
            'features.*'     => ['string', Rule::in(TenantFeature::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'admin_email.unique' => 'El email ya existe en otro tenant.',
        ];
    }
}

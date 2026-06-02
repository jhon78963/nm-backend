<?php

namespace App\Administration\User\Requests;

use App\Administration\User\Concerns\GuardsActorTenantScope;
use App\Administration\User\Concerns\GuardsSuperAdminRoleAssignment;
use App\Administration\User\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserCreateRequest extends FormRequest
{
    use GuardsActorTenantScope;
    use GuardsSuperAdminRoleAssignment;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('create', User::class)) {
            return false;
        }

        if (! $this->authorizesActorTenantScope()) {
            $this->failedActorTenantScopeAuthorization();
        }

        return $this->authorizesSuperAdminRoleAssignment();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('passwordConfirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('passwordConfirmation')]);
        }
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
            'warehouseId' => [
                'required',
                Rule::exists('warehouses', 'id')->where('tenant_id', $this->input('tenantId')),
            ],
            'password' => 'required|string|min:8|confirmed',
            'file' => 'nullable|mimes:jpeg,png,jpg,webp|max:2048',
        ];
    }
}

<?php

namespace App\Administration\User\Requests;

use App\Administration\User\Concerns\GuardsActorTenantScope;
use App\Administration\User\Concerns\GuardsSuperAdminRoleAssignment;
use App\Administration\User\Concerns\ValidatesSuperAdminScope;
use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;
use App\Shared\Foundation\Rules\ValidMagicBytes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    use GuardsActorTenantScope;
    use GuardsSuperAdminRoleAssignment;
    use ValidatesSuperAdminScope;

    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if ($actor === null || ! ($target instanceof User)) {
            return false;
        }

        if (! $actor->can('update', $target)) {
            return false;
        }

        if (! $this->authorizesActorTenantScope()) {
            $this->failedActorTenantScopeAuthorization();
        }

        if (! $this->authorizesSuperAdminRoleAssignment()) {
            $this->failedAuthorization();
        }

        $target = $this->route('user');
        if (
            $target instanceof User
            && method_exists($target, 'hasRole')
            && $target->hasRole(SuperAdminRole::NAME)
            && $this->filled('roleNames')
        ) {
            throw new AuthorizationException(
                'Los usuarios Super Admin son internos; su rol no puede modificarse desde el sistema.',
            );
        }

        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $requiresScope = $this->userScopeRequiresTenantWarehouse();

        return [
            'username' => ['sometimes', 'string', 'max:25', Rule::unique('users', 'username')->ignore($userId)],
            'name' => 'sometimes|max:25',
            'surname' => 'sometimes|max:25',
            'file' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp', 'max:2048', new ValidMagicBytes(['jpeg', 'png', 'webp'])],
            'warehouseId' => array_merge(
                $requiresScope
                    ? ['sometimes', Rule::exists('warehouses', 'id')->where('tenant_id', $this->tenantIdForWarehouseValidation())]
                    : ['sometimes', 'nullable', 'prohibited'],
            ),
            'tenantId' => array_merge(
                $requiresScope ? ['sometimes', 'exists:tenants,id'] : ['sometimes', 'nullable', 'prohibited'],
            ),
            'roleNames' => ['sometimes', 'array', 'min:1'],
            'roleNames.*' => [
                'string',
                Rule::notIn([SuperAdminRole::NAME]),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}

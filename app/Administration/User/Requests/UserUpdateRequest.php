<?php

namespace App\Administration\User\Requests;

use App\Administration\User\Concerns\GuardsActorTenantScope;
use App\Administration\User\Concerns\GuardsSuperAdminRoleAssignment;
use App\Administration\User\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    use GuardsActorTenantScope;
    use GuardsSuperAdminRoleAssignment;

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

        return $this->authorizesSuperAdminRoleAssignment();
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
            'warehouseId' => [
                'sometimes',
                Rule::exists('warehouses', 'id')->where('tenant_id', $this->tenantIdForWarehouseValidation()),
            ],
            'tenantId' => ['sometimes', 'exists:tenants,id'],
            'roleNames' => ['sometimes', 'array', 'min:1'],
            'roleNames.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}

<?php

namespace App\Inventory\Warehouse\Requests;

use App\Administration\User\Support\SuperAdminRole;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class WarehouseUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = $this->route('warehouse');

        if (! ($warehouse instanceof Warehouse)) {
            return false;
        }

        if (! $this->authorizesActorTenantScope($warehouse)) {
            $this->failedActorTenantScopeAuthorization();
        }

        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:25',
            'tenantId' => ['sometimes', 'exists:tenants,id'],
        ];
    }

    private function authorizesActorTenantScope(Warehouse $warehouse): bool
    {
        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        if (
            method_exists($actor, 'hasRole')
            && $actor->hasRole(SuperAdminRole::NAME)
        ) {
            return true;
        }

        $actorTenantId = (int) $actor->tenant_id;
        $tenantId = $this->input('tenantId', $this->input('tenant_id'));

        if ($tenantId !== null && (int) $tenantId !== $actorTenantId) {
            return false;
        }

        return (int) $warehouse->tenant_id === $actorTenantId;
    }

    private function failedActorTenantScopeAuthorization(): never
    {
        throw new AuthorizationException(
            'No tiene permiso para gestionar tiendas de otro tenant.',
        );
    }
}

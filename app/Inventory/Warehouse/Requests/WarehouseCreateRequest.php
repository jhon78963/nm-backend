<?php

namespace App\Inventory\Warehouse\Requests;

use App\Administration\User\Support\SuperAdminRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class WarehouseCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->authorizesActorTenantScope()) {
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
            'name' => 'required|string|max:25',
            'tenantId' => ['required', 'exists:tenants,id'],
        ];
    }

    private function authorizesActorTenantScope(): bool
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

        $tenantId = $this->input('tenantId', $this->input('tenant_id'));

        return $tenantId !== null
            && (int) $tenantId === (int) $actor->tenant_id;
    }

    private function failedActorTenantScopeAuthorization(): never
    {
        throw new AuthorizationException(
            'No tiene permiso para crear tiendas en otro tenant.',
        );
    }
}

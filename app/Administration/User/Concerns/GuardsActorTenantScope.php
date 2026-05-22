<?php

namespace App\Administration\User\Concerns;

use Illuminate\Auth\Access\AuthorizationException;

trait GuardsActorTenantScope
{
    public function authorizesActorTenantScope(): bool
    {
        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        if (
            method_exists($actor, 'hasRole')
            && $actor->hasRole(GuardsSuperAdminRoleAssignment::SUPER_ADMIN_ROLE)
        ) {
            return true;
        }

        $tenantId = $this->input('tenantId', $this->input('tenant_id'));

        if ($tenantId === null) {
            return true;
        }

        return (int) $tenantId === (int) $actor->tenant_id;
    }

    protected function tenantIdForWarehouseValidation(): mixed
    {
        return $this->input('tenantId')
            ?? $this->input('tenant_id')
            ?? $this->route('user')?->tenant_id;
    }

    protected function failedActorTenantScopeAuthorization(): never
    {
        throw new AuthorizationException(
            'No tiene permiso para gestionar usuarios de otro tenant.',
        );
    }
}

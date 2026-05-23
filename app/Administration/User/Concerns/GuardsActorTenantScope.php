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

        $actorTenantId = (int) $actor->tenant_id;
        $tenantId = $this->input('tenantId', $this->input('tenant_id'));

        if ($tenantId !== null && (int) $tenantId !== $actorTenantId) {
            return false;
        }

        $routeUser = $this->route('user');

        if ($routeUser !== null) {
            return (int) $routeUser->tenant_id === $actorTenantId;
        }

        return true;
    }

    protected function tenantIdForWarehouseValidation(): mixed
    {
        return $this->input('tenantId')
            ?? $this->input('tenant_id')
            ?? $this->route('user')?->tenant_id
            ?? $this->user()?->tenant_id;
    }

    protected function failedActorTenantScopeAuthorization(): never
    {
        throw new AuthorizationException(
            'No tiene permiso para gestionar usuarios de otro tenant.',
        );
    }
}

<?php

namespace App\Administration\Role\Concerns;

use App\Administration\User\Support\SuperAdminRole;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

/**
 * SEC-001 — RBAC tenant-scope isolation.
 *
 * Estrategia elegida: B — sin activar Spatie teams.
 * Se agrega tenant_id nullable a la tabla roles:
 *   - NULL  → rol de sistema por tenant_id; los built-in (SEC-002) no son mutables vía API.
 *   - filled → rol custom del tenant: solo el admin del mismo tenant puede modificarlo.
 *
 * Esto garantiza que un admin del tenant A no pueda alterar roles que
 * afecten al tenant B ni modificar roles globales del sistema.
 */
trait GuardsRoleTenantScope
{
    /**
     * Verifica que el actor autenticado puede gestionar el rol dado.
     * Lanza AuthorizationException si no está autorizado.
     */
    protected function authorizeRoleTenantScope(Role $role): void
    {
        if (! $this->actorCanManageRole($role)) {
            throw new AuthorizationException(
                'No tiene permiso para modificar roles de otro tenant.',
            );
        }
    }

    /**
     * Retorna true si el actor puede gestionar el rol.
     */
    private function actorCanManageRole(Role $role): bool
    {
        $actor = auth()->user();

        if ($actor === null) {
            return false;
        }

        // Super Admin gestiona cualquier rol sin restricción.
        if (method_exists($actor, 'hasRole') && $actor->hasRole(SuperAdminRole::NAME)) {
            return true;
        }

        // Roles de sistema (tenant_id NULL) solo los gestiona Super Admin.
        if ($role->tenant_id === null) {
            return false;
        }

        // Rol custom: solo si pertenece al mismo tenant del actor.
        return (int) $role->tenant_id === (int) $actor->tenant_id;
    }

    /**
     * Retorna el tenant_id que debe asignarse a un nuevo rol según el actor.
     * Super Admin crea roles de sistema (null); el resto crea roles con su propio tenant.
     */
    protected function tenantIdForNewRole(): ?int
    {
        $actor = auth()->user();

        if ($actor === null) {
            return null;
        }

        if (method_exists($actor, 'hasRole') && $actor->hasRole(SuperAdminRole::NAME)) {
            return null; // rol de sistema
        }

        return (int) $actor->tenant_id ?: null;
    }
}

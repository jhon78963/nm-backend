<?php

namespace App\Administration\Role\Concerns;

use App\Administration\Role\Support\BuiltinSystemRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

/**
 * SEC-002 — Impide delete/update/syncPermissions sobre roles built-in del sistema.
 */
trait GuardsBuiltinSystemRoles
{
    protected function authorizeBuiltinSystemRoleMutation(Role $role): void
    {
        if (! BuiltinSystemRoles::isProtected($role)) {
            return;
        }

        throw new AuthorizationException(
            'Los roles del sistema (Super Admin, Vendedora y Vendedor) no pueden modificarse ni eliminarse.',
        );
    }
}

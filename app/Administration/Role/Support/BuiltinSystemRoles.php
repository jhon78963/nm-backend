<?php

namespace App\Administration\Role\Support;

use Spatie\Permission\Models\Role;

/**
 * SEC-002 — Roles del sistema cuyas definiciones no pueden mutarse vía API.
 */
final class BuiltinSystemRoles
{
    public const NAMES = [
        'Super Admin',
        'Vendedora',
        'Vendedor',
    ];

    public static function isProtected(Role $role): bool
    {
        return in_array($role->name, self::NAMES, true);
    }
}

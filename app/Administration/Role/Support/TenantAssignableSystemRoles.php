<?php

namespace App\Administration\Role\Support;

/**
 * Roles globales que un admin de tenant puede ver y asignar a usuarios,
 * pero no modificar (tenant_id NULL en BD).
 */
final class TenantAssignableSystemRoles
{
    public const NAMES = [
        'Admin',
        'Vendedora',
        'Vendedor',
    ];

    public static function isAssignable(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}

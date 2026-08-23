<?php

namespace App\Administration\User\Concerns;

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;

trait ValidatesSuperAdminScope
{
    protected function assignsSuperAdminRole(): bool
    {
        $roleNames = $this->input('roleNames', []);

        if (! is_array($roleNames)) {
            return false;
        }

        return in_array(SuperAdminRole::NAME, $roleNames, true);
    }

    protected function targetUserIsSuperAdmin(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            && method_exists($user, 'hasRole')
            && $user->hasRole(SuperAdminRole::NAME);
    }

    /** Super Admin nunca lleva tenant ni tienda asignada. */
    protected function userScopeRequiresTenantWarehouse(): bool
    {
        if ($this->assignsSuperAdminRole()) {
            return false;
        }

        $roleNames = $this->input('roleNames');

        if (is_array($roleNames)) {
            return true;
        }

        return ! $this->targetUserIsSuperAdmin();
    }
}

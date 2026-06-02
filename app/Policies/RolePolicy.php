<?php

namespace App\Policies;

use App\Administration\User\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('role.getAll');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('role.get');
    }

    public function create(User $user): bool
    {
        return $user->can('role.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('role.update');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('role.delete');
    }

    public function syncPermissions(User $user, Role $role): bool
    {
        return $user->can('role.syncPermissions');
    }

    public function viewPermissions(User $user): bool
    {
        return $user->can('role.permissionsIndex');
    }
}

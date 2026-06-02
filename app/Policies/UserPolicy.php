<?php

namespace App\Policies;

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('user.getAll');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('user.get')
            && $this->actorCanAccessUser($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->can('user.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('user.update')
            && $this->actorCanAccessUser($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('user.delete')
            && $this->actorCanAccessUser($actor, $target);
    }

    private function actorCanAccessUser(User $actor, User $target): bool
    {
        if (
            method_exists($actor, 'hasRole')
            && $actor->hasRole(SuperAdminRole::NAME)
        ) {
            return true;
        }

        return (int) $actor->tenant_id === (int) $target->tenant_id;
    }
}

<?php

namespace App\Administration\User\Concerns;

use App\Administration\User\Support\SuperAdminRole;
use Illuminate\Auth\Access\AuthorizationException;

trait GuardsSuperAdminRoleAssignment
{

    public function authorizesSuperAdminRoleAssignment(): bool
    {
        if (! $this->payloadAssignsSuperAdminRole()) {
            return true;
        }

        $actor = $this->user();

        return $actor !== null
            && method_exists($actor, 'hasRole')
            && $actor->hasRole(SuperAdminRole::NAME);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(
            'No tiene permiso para asignar el rol Super Admin.',
        );
    }

    /**
     * @return list<string>
     */
    protected function roleNamesFromPayload(): array
    {
        $roles = $this->input('roleNames', $this->input('role_names'));

        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, static fn ($role): bool => is_string($role) && $role !== ''));
    }

    protected function payloadAssignsSuperAdminRole(): bool
    {
        return in_array(SuperAdminRole::NAME, $this->roleNamesFromPayload(), true);
    }
}

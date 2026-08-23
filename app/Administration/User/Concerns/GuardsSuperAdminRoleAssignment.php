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

        // Rol interno: no se crea ni asigna vía API (solo seeders / consola).
        return false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(
            'El rol Super Admin es interno y no puede asignarse desde el sistema.',
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

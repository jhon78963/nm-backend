<?php

namespace App\Administration\Role\Services;

use App\Administration\Role\Models\Role;
use App\Shared\Foundation\Services\ModelService;

class RoleService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function create(array $newRole): void
    {
        $this->modelService->create(
            model: new Role(),
            data: $newRole
        );
    }

    public function delete(Role $role): void
    {
        $this->modelService->delete(
            model: $role,
        );
    }

    public function update(Role $role, array $editRole): void
    {
        $this->modelService->update(
            model: $role,
            data: $editRole,
        );
    }

    public function validate(Role $role, string $modelName): Role
    {
        return $this->modelService->validate(
            model: $role,
            modelName: $modelName,
        );
    }
}

<?php

namespace App\Administration\Role\Services;

use App\Administration\Role\Models\Role;
use App\Shared\Foundation\Services\ModelService;

class RoleService extends ModelService
{
    public function __construct(Role $role)
    {
        parent::__construct($role);
    }
}

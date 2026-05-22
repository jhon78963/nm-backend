<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Permission::class) || ! class_exists(Role::class)) {
            return;
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';

        Permission::query()->firstOrCreate(
            ['name' => 'report.products', 'guard_name' => $guard],
            ['name' => 'report.products', 'guard_name' => $guard],
        );

        $rolesWithReportIndex = Role::query()
            ->where('guard_name', $guard)
            ->whereHas('permissions', fn ($query) => $query->where('name', 'report.index'))
            ->get();

        foreach ($rolesWithReportIndex as $role) {
            $role->givePermissionTo('report.products');
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin rollback.
    }
};

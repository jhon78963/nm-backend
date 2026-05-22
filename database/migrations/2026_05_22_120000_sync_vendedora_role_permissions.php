<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function vendedorPermissionNames(): array
    {
        return [
            'pos.searchProduct',
            'pos.searchCustomer',
            'pos.checkout',
            'cashflow.getDaily',
            'cashflow.store',
        ];
    }

    public function up(): void
    {
        if (! class_exists(Permission::class) || ! class_exists(Role::class)) {
            return;
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';

        foreach ($this->vendedorPermissionNames() as $name) {
            Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['name' => $name, 'guard_name' => $guard],
            );
        }

        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $this->vendedorPermissionNames())
            ->get();

        foreach (['Vendedora', 'Vendedor'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->first();

            if ($role !== null) {
                $role->syncPermissions($permissions);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin rollback: los permisos previos eran demasiado amplios (deny-list).
    }
};

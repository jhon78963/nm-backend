<?php

namespace App\Administration\User\Seeders;

use App\Administration\User\Models\User;
use Database\Seeders\Support\SeederDefaultPassword;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roleSuper = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->first();
        $roleVendedora = Role::query()->where('name', 'Vendedora')->where('guard_name', 'web')->first();

        $seedPassword = SeederDefaultPassword::hashed($this);

        $defaultWarehouseId = (int) (\App\Inventory\Warehouse\Models\Warehouse::query()->orderBy('id')->value('id') ?? 1);
        $defaultTenantId = (int) (\App\Inventory\Warehouse\Models\Warehouse::query()->find($defaultWarehouseId)?->tenant_id ?? 1);

        $make = function (array $attrs, $role) use ($defaultWarehouseId, $defaultTenantId, $seedPassword) {
            $user = User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'username' => $attrs['username'],
                    'name' => $attrs['name'],
                    'surname' => $attrs['surname'],
                ]
            );
            $user->password = $seedPassword;
            $user->tenant_id = $defaultTenantId;
            $user->warehouse_id = $defaultWarehouseId;
            $user->save();
            if ($role) {
                $user->syncRoles([$role]);
            }
        };

        $make([
            'username' => 'jhon.livias',
            'email' => 'jhonlivias3@gmail.com',
            'name' => 'Jhon',
            'surname' => 'Livias',
        ], $roleSuper);

        $make([
            'username' => 'user.admin',
            'email' => 'user.admin@gmail.com',
            'name' => 'User',
            'surname' => 'Admin',
        ], $roleSuper);

        $make([
            'username' => 'maritex',
            'email' => 'maritex@gmail.com',
            'name' => 'User',
            'surname' => 'Admin',
        ], $roleSuper);

        $make([
            'username' => 'user.employee',
            'email' => 'user.employee@gmail.com',
            'name' => 'User',
            'surname' => 'Employee',
        ], $roleVendedora);
    }
}

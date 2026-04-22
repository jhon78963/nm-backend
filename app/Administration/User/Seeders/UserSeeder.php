<?php

namespace App\Administration\User\Seeders;

use App\Administration\User\Models\User;
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

        $make = function (array $attrs, $role) {
            $user = User::query()->firstOrCreate(
                ['email' => $attrs['email']],
                [
                    'username' => $attrs['username'],
                    'name' => $attrs['name'],
                    'surname' => $attrs['surname'],
                    'password' => $attrs['passwordPlain'],
                    'tenant_id' => 1,
                    'warehouse_id' => 1,
                ]
            );
            if ($role) {
                $user->syncRoles([$role]);
            }
        };

        $make([
            'username' => 'jhon.livias',
            'email' => 'jhonlivias3@gmail.com',
            'name' => 'Jhon',
            'surname' => 'Livias',
            'passwordPlain' => '123qwe123',
        ], $roleSuper);

        $make([
            'username' => 'user.admin',
            'email' => 'user.admin@gmail.com',
            'name' => 'User',
            'surname' => 'Admin',
            'passwordPlain' => 'password',
        ], $roleSuper);

        $make([
            'username' => 'maritex',
            'email' => 'maritex@gmail.com',
            'name' => 'User',
            'surname' => 'Admin',
            'passwordPlain' => '18146819',
        ], $roleSuper);

        $make([
            'username' => 'user.employee',
            'email' => 'user.employee@gmail.com',
            'name' => 'User',
            'surname' => 'Employee',
            'passwordPlain' => 'password',
        ], $roleVendedora);
    }
}

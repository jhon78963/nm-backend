<?php

namespace Database\Seeders;

use App\Administrations\Users\Seeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            \App\Administrations\Warehouses\Seeders\WarehouseSeeder::class,
            RoleAndPermissionSeeder::class,
            UserSeeder::class,
            AssignUserWarehousesSeeder::class,
        ]);
    }
}

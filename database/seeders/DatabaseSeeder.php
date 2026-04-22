<?php

namespace Database\Seeders;

use App\Administration\User\Seeders\UserSeeder;
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
            RoleAndPermissionSeeder::class,
            UserSeeder::class,
        ]);
    }
}

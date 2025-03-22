<?php

namespace Database\Seeders;

use App\Gender\Seeders\GenderSeeder;
use App\Role\Seeders\RoleSeeder;
use App\User\Seeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            GenderSeeder::class,
        ]);
    }
}

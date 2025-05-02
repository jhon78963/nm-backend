<?php

namespace Database\Seeders;

use App\Gender\Seeders\GenderSeeder;
use App\Product\Seeders\ProductSeeder;
use App\Role\Seeders\RoleSeeder;
use App\Size\Seeders\SizeSeeder;
use App\Size\Seeders\SizeTypeSeeder;
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
            SizeTypeSeeder::class,
            SizeSeeder::class,
            ProductSeeder::class,
        ]);
    }
}

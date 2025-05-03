<?php

namespace Database\Seeders;

use App\Gender\Seeders\GenderSeeder;
use App\Product\Seeders\Product2Seeder;
use App\Product\Seeders\Product3Seeder;
use App\Product\Seeders\Product4Seeder;
use App\Product\Seeders\Product5Seeder;
use App\Product\Seeders\Product6Seeder;
use App\Product\Seeders\Product7Seeder;
use App\Product\Seeders\Product8Seeder;
use App\Product\Seeders\Product9Seeder;
use App\Product\Seeders\Product10Seeder;
use App\Product\Seeders\Product11Seeder;
use App\Product\Seeders\Product12Seeder;
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
            Product2Seeder::class,
            Product3Seeder::class,
            Product4Seeder::class,
            Product5Seeder::class,
            Product6Seeder::class,
            Product7Seeder::class,
            Product8Seeder::class,
            Product9Seeder::class,
            Product10Seeder::class,
            Product11Seeder::class,
            Product12Seeder::class,
        ]);
    }
}

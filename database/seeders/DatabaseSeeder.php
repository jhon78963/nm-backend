<?php

namespace Database\Seeders;

use App\Administration\Role\Seeders\RoleSeeder;
use App\Administration\User\Seeders\UserSeeder;
use App\Inventory\Gender\Seeders\GenderSeeder;
use App\Inventory\Product\Seeders\Product10Seeder;
use App\Inventory\Product\Seeders\Product11Seeder;
use App\Inventory\Product\Seeders\Product12Seeder;
use App\Inventory\Product\Seeders\Product2Seeder;
use App\Inventory\Product\Seeders\Product3Seeder;
use App\Inventory\Product\Seeders\Product4Seeder;
use App\Inventory\Product\Seeders\Product5Seeder;
use App\Inventory\Product\Seeders\Product6Seeder;
use App\Inventory\Product\Seeders\Product7Seeder;
use App\Inventory\Product\Seeders\Product8Seeder;
use App\Inventory\Product\Seeders\Product9Seeder;
use App\Inventory\Product\Seeders\ProductSeeder;
use App\Inventory\Size\Seeders\SizeSeeder;
use App\Inventory\Size\Seeders\SizeTypeSeeder;
use App\Inventory\Warehouse\Seeders\WarehouseSeeder;
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
            WarehouseSeeder::class,
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

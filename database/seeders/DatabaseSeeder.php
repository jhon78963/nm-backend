<?php

namespace Database\Seeders;

use App\Gender\Seeders\GenderSeeder;
use App\Role\Seeders\RoleSeed;
use App\User\Seeders\UserSeed;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeed::class,
            UserSeed::class,
            GenderSeeder::class,
        ]);
    }
}

<?php

namespace App\Inventory\Size\Seeders;

use App\Inventory\Size\Models\SizeType;
use Illuminate\Database\Seeder;

class SizeTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $size = new SizeType();
        $size->description = 'Adulto letras'; // 1
        $size->save();

        $size = new SizeType();
        $size->description = 'Adulto números'; // 2
        $size->save();

        $size = new SizeType();
        $size->description = 'Niños'; // 3
        $size->save();
    }
}

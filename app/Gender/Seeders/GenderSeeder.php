<?php

namespace App\Gender\Seeders;

use App\Gender\Models\Gender;
use Illuminate\Database\Seeder;

class GenderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gender = new Gender();
        $gender->name = 'Caballeros';
        $gender->short_name = 'M';
        $gender->save();

        $gender = new Gender();
        $gender->name = 'Damas';
        $gender->short_name = 'F';
        $gender->save();

        $gender = new Gender();
        $gender->name = 'Niños';
        $gender->short_name = 'N';
        $gender->save();
    }
}

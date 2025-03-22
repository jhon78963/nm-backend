<?php

namespace App\Product\Seeders;

use App\Product\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gender = new Product();
        $gender->name = 'Masculino';
        $gender->short_name = 'M';
        $gender->save();

        $gender = new Product();
        $gender->name = 'Femenino';
        $gender->short_name = 'F';
        $gender->save();
    }
}

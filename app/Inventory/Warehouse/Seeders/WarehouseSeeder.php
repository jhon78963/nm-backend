<?php

namespace App\Inventory\Warehouse\Seeders;

use App\Inventory\Warehouse\Model\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouse = new Warehouse();
        $warehouse->name = 'ANTONY';
        $warehouse->save();

        $warehouse = new Warehouse();
        $warehouse->name = 'MARY';
        $warehouse->save();
    }
}

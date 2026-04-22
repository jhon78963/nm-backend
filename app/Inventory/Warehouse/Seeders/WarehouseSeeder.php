<?php

namespace App\Inventory\Warehouse\Seeders;

use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = 1;

        $w = new Warehouse();
        $w->name = 'ANTONY';
        $w->tenant_id = $tenantId;
        $w->save();

        $w = new Warehouse();
        $w->name = 'MARY';
        $w->tenant_id = $tenantId;
        $w->save();
    }
}

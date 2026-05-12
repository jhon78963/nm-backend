<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateSalesWarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $updatedRows = DB::table('sales')
            ->update(['warehouse_id' => 1, 'tenant_id' => 2]);

        $this->command->info("¡Listo mi king! Se actualizaron {$updatedRows} ventas al warehouse_id 1 y tenant_id 2.");
    }
}

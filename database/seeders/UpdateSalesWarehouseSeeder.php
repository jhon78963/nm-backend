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
            ->whereNull('warehouse_id') // Opcional: solo actualiza si está nulo
            ->update(['warehouse_id' => 1]);

        $this->command->info("¡Listo mi king! Se actualizaron {$updatedRows} ventas al warehouse_id 1.");
    }
}

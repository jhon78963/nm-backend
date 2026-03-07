<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FixCashMovementsDateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $updatedRows = DB::table('cash_movements')
            ->whereNull('date')
            ->update(['date' => DB::raw('creation_time')]);

        $this->command->info("Se han actualizado $updatedRows registros en cash_movements.");
    }
}

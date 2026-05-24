<?php

namespace Database\Seeders;

use App\Finance\Sale\Models\DocumentSeries;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;

class DocumentSeriesSeeder extends Seeder
{
    /**
     * Crea las series de comprobantes para cada almacén registrado.
     * Usa updateOrCreate para ser idempotente (re-ejecutar no duplica filas).
     */
    public function run(): void
    {
        $warehouses = Warehouse::all();

        if ($warehouses->isEmpty()) {
            $this->command->warn('No hay almacenes registrados. Omitiendo DocumentSeriesSeeder.');
            return;
        }

        $templates = [
            ['document_type' => 'BOLETA',  'serie' => 'B001'],
            ['document_type' => 'FACTURA', 'serie' => 'F001'],
        ];

        foreach ($warehouses as $warehouse) {
            foreach ($templates as $template) {
                DocumentSeries::updateOrCreate(
                    [
                        'warehouse_id'  => $warehouse->id,
                        'document_type' => $template['document_type'],
                        'serie'         => $template['serie'],
                    ],
                    ['current_number' => 0]
                );

                $this->command->info(
                    "  Serie {$template['serie']} ({$template['document_type']}) → almacén #{$warehouse->id} ({$warehouse->name})"
                );
            }
        }
    }
}

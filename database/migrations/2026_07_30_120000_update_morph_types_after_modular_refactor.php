<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Actualiza class names persistidos antes del refactor modular (namespaces v1 → v2).
     */
    public function up(): void
    {
        $replacements = [
            'App\\Inventory\\Product\\Models\\Product' => 'App\\Inventories\\Products\\Models\\Product',
            'App\\Inventory\\Purchase\\Models\\Purchase' => 'App\\Inventories\\Purchases\\Models\\Purchase',
            'App\\Finance\\Sale\\Models\\Sale' => 'App\\Finances\\Sales\\Models\\Sale',
            'App\\Finance\\CashMovement\\Models\\CashMovement' => 'App\\Finances\\CashMovements\\Models\\CashMovement',
            'App\\Directory\\Team\\Models\\TeamPayment' => 'App\\Directories\\Teams\\Models\\TeamPayment',
            'App\\Administration\\User\\Models\\User' => 'App\\Administrations\\Users\\Models\\User',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('media')
                ->where('mediable_type', $from)
                ->update(['mediable_type' => $to]);

            DB::table('inventory_movements')
                ->where('reference_type', $from)
                ->update(['reference_type' => $to]);
        }
    }

    public function down(): void
    {
        $replacements = [
            'App\\Inventories\\Products\\Models\\Product' => 'App\\Inventory\\Product\\Models\\Product',
            'App\\Inventories\\Purchases\\Models\\Purchase' => 'App\\Inventory\\Purchase\\Models\\Purchase',
            'App\\Finances\\Sales\\Models\\Sale' => 'App\\Finance\\Sale\\Models\\Sale',
            'App\\Finances\\CashMovements\\Models\\CashMovement' => 'App\\Finance\\CashMovement\\Models\\CashMovement',
            'App\\Directories\\Teams\\Models\\TeamPayment' => 'App\\Directory\\Team\\Models\\TeamPayment',
            'App\\Administrations\\Users\\Models\\User' => 'App\\Administration\\User\\Models\\User',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('media')
                ->where('mediable_type', $from)
                ->update(['mediable_type' => $to]);

            DB::table('inventory_movements')
                ->where('reference_type', $from)
                ->update(['reference_type' => $to]);
        }
    }
};

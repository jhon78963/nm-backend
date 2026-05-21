<?php

namespace App\Inventory\Concerns;

use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Tras Política A: coherencia entre stock maestro (balance color_id null) y suma de variantes por color en inventory_balances.
 */
trait AssertsInventoryMasterMatchesColorPivotSum
{
    private function assertMasterMatchesColorsSum(int $psId): void
    {
        if ($psId <= 0) {
            return;
        }

        $pivotCount = (int) DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->count();

        if ($pivotCount === 0) {
            return;
        }

        $warehouseId = (int) (DB::table('product_size as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.id', $psId)
            ->value('p.warehouse_id') ?? 0);

        if ($warehouseId < 1) {
            return;
        }

        $masterQty = (int) (DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('product_size_id', $psId)
            ->whereNull('color_id')
            ->value('quantity') ?? 0);

        $sumColors = (int) DB::table('inventory_balances as ib')
            ->join('product_size_color as psc', static function ($join): void {
                $join->on('psc.product_size_id', '=', 'ib.product_size_id')
                    ->on('psc.color_id', '=', 'ib.color_id');
            })
            ->where('ib.warehouse_id', $warehouseId)
            ->where('ib.product_size_id', $psId)
            ->sum('ib.quantity');

        if ($masterQty < $sumColors) {
            throw new Exception(
                "Inconsistencia crítica prevenida: La suma de stock por color ({$sumColors}) supera el saldo maestro de la talla ({$masterQty}). Operación abortada.",
            );
        }
    }
}

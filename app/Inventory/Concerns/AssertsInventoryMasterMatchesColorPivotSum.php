<?php

namespace App\Inventory\Concerns;

use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza coherencia maestro vs sum(pivotes por color). Solo vigila registros donde
 * ya existen pivotes `product_size_color`: tallas sólo-maestro (sin pivotes) quedan fuera del chequeo.
 */
trait AssertsInventoryMasterMatchesColorPivotSum
{
    private function assertMasterMatchesColorsSum(int $psId): void
    {
        if ($psId <= 0) {
            return;
        }

        $agg = DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->selectRaw('COUNT(*) AS pivot_rows, COALESCE(SUM(stock), 0) AS sum_stock')
            ->first();

        if ($agg === null || (int) $agg->pivot_rows === 0) {
            return;
        }

        $suma_colores = (int) $agg->sum_stock;

        $stock_maestro = (int) (DB::table('product_size')
            ->where('id', $psId)
            ->value('stock') ?? 0);

        if ($stock_maestro !== $suma_colores) {
            throw new Exception(
                "Inconsistencia crítica prevenida: El stock del maestro ({$stock_maestro}) no coincide con la suma de sus colores ({$suma_colores}). Operación abortada.",
            );
        }
    }
}

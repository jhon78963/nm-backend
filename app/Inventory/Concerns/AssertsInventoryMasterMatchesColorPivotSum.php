<?php

namespace App\Inventory\Concerns;

use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza coherencia: la suma de stocks por color no puede superar el stock maestro (`product_size`).
 * El maestro puede ser mayor (unidades sin desglose o techo fijado desde tallas); antes se exigía igualdad estricta maestro/suma.
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

        if ($stock_maestro < $suma_colores) {
            throw new Exception(
                "Inconsistencia crítica prevenida: La suma de stock por color ({$suma_colores}) supera el stock maestro de la talla ({$stock_maestro}). Operación abortada.",
            );
        }
    }
}

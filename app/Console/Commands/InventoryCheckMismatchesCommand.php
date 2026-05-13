<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryCheckMismatchesCommand extends Command
{
    protected $signature = 'inventory:check-mismatches';

    protected $description = 'Detecta tallas con desglose por color donde el stock maestro no coincide con la suma de stocks en product_size_color.';

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                ps.product_id,
                ps.size_id,
                ps.stock AS master_stock,
                COALESCE(SUM(psc.stock), 0)::bigint AS color_sum,
                (ps.stock - COALESCE(SUM(psc.stock), 0))::bigint AS diff
            FROM product_size ps
            INNER JOIN product_size_color psc ON psc.product_size_id = ps.id
            GROUP BY ps.id, ps.product_id, ps.size_id, ps.stock
            HAVING ps.stock <> COALESCE(SUM(psc.stock), 0)
            ORDER BY ps.product_id ASC, ps.size_id ASC
            SQL);

        if ($rows === []) {
            $this->line('<fg=green>Inventario cuadrado: no hay discrepancias entre stock maestro y suma de colores.</fg=green>');

            return self::SUCCESS;
        }

        $tableRows = array_map(static function (object $r): array {
            return [
                (string) $r->product_id,
                (string) $r->size_id,
                (string) $r->master_stock,
                (string) $r->color_sum,
                (string) $r->diff,
            ];
        }, $rows);

        $this->table(
            ['Product ID', 'Size ID', 'Stock Maestro', 'Suma de Colores', 'Diferencia'],
            $tableRows,
        );

        return self::FAILURE;
    }
}

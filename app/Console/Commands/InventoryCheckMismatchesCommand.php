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
                COALESCE(master.total, 0)::bigint AS master_stock,
                COALESCE(color.total, 0)::bigint AS color_sum,
                (COALESCE(master.total, 0) - COALESCE(color.total, 0))::bigint AS diff
            FROM product_size ps
            INNER JOIN (
                SELECT product_size_id
                FROM product_size_color
                GROUP BY product_size_id
            ) psc ON psc.product_size_id = ps.id
            LEFT JOIN (
                SELECT product_size_id, SUM(quantity) AS total
                FROM inventory_balances
                WHERE color_id IS NULL
                GROUP BY product_size_id
            ) master ON master.product_size_id = ps.id
            LEFT JOIN (
                SELECT product_size_id, SUM(quantity) AS total
                FROM inventory_balances
                WHERE color_id IS NOT NULL
                GROUP BY product_size_id
            ) color ON color.product_size_id = ps.id
            WHERE COALESCE(master.total, 0) <> COALESCE(color.total, 0)
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

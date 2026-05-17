<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryCheckMismatchesCommand extends Command
{
    protected $signature = 'inventory:check-mismatches';

    protected $description = 'Detecta tallas con desglose por color donde el stock maestro (color_id nulo) no coincide con la suma por color, por almacén.';

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                k.warehouse_id,
                w.name AS warehouse_name,
                ps.product_id,
                ps.size_id,
                COALESCE(master.total, 0) AS master_stock,
                COALESCE(color.total, 0) AS color_sum,
                COALESCE(master.total, 0) - COALESCE(color.total, 0) AS diff
            FROM (
                SELECT DISTINCT ib.warehouse_id, ib.product_size_id
                FROM inventory_balances ib
                WHERE EXISTS (
                    SELECT 1
                    FROM product_size_color psc
                    WHERE psc.product_size_id = ib.product_size_id
                )
            ) k
            INNER JOIN product_size ps ON ps.id = k.product_size_id
            LEFT JOIN warehouses w ON w.id = k.warehouse_id
            LEFT JOIN (
                SELECT warehouse_id, product_size_id, SUM(quantity) AS total
                FROM inventory_balances
                WHERE color_id IS NULL
                GROUP BY warehouse_id, product_size_id
            ) master
                ON master.warehouse_id = k.warehouse_id
               AND master.product_size_id = k.product_size_id
            LEFT JOIN (
                SELECT warehouse_id, product_size_id, SUM(quantity) AS total
                FROM inventory_balances
                WHERE color_id IS NOT NULL
                GROUP BY warehouse_id, product_size_id
            ) color
                ON color.warehouse_id = k.warehouse_id
               AND color.product_size_id = k.product_size_id
            WHERE COALESCE(master.total, 0) <> COALESCE(color.total, 0)
            ORDER BY k.warehouse_id ASC, ps.product_id ASC, ps.size_id ASC
            SQL);

        if ($rows === []) {
            $this->line('<fg=green>Inventario cuadrado: no hay discrepancias entre stock maestro y suma de colores por almacén.</fg=green>');

            return self::SUCCESS;
        }

        $tableRows = array_map(static function (object $r): array {
            $warehouseLabel = $r->warehouse_name !== null && $r->warehouse_name !== ''
                ? (string) $r->warehouse_name
                : '—';

            return [
                (string) $r->warehouse_id,
                $warehouseLabel,
                (string) $r->product_id,
                (string) $r->size_id,
                (string) $r->master_stock,
                (string) $r->color_sum,
                (string) $r->diff,
            ];
        }, $rows);

        $this->table(
            ['Warehouse ID', 'Almacén', 'Product ID', 'Size ID', 'Stock Maestro', 'Suma Colores', 'Diferencia'],
            $tableRows,
        );

        return self::FAILURE;
    }
}

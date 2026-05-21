<?php

namespace App\Console\Commands;

use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryFixMismatchesCommand extends Command
{
    protected $signature = 'inventory:fix-mismatches
                            {--product-id= : Corregir solo un producto específico}
                            {--dry-run : Mostrar qué se corregiría sin aplicar cambios}';

    protected $description = 'Alinea el stock maestro con la suma por color en tallas con variantes desincronizadas.';

    public function __construct(
        protected InventoryMovementService $inventoryMovementService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $productId = $this->option('product-id');
        $dryRun = (bool) $this->option('dry-run');
        $productFilter = $productId !== null && $productId !== '' ? (int) $productId : null;

        $orphans = $this->findOrphanColorBalances($productFilter);
        if ($orphans !== []) {
            $this->warn('Balances huérfanos (color sin fila en product_size_color):');
            $this->table(
                ['Balance ID', 'Warehouse', 'Product Size', 'Color ID', 'Cantidad'],
                array_map(static fn (object $r): array => [
                    (string) $r->id,
                    (string) $r->warehouse_id,
                    (string) $r->product_size_id,
                    (string) $r->color_id,
                    (string) $r->quantity,
                ], $orphans),
            );
        }

        if (! $dryRun && $orphans !== []) {
            foreach ($orphans as $orphan) {
                $this->inventoryMovementService->zeroColorBalance(
                    (int) $orphan->warehouse_id,
                    (int) $orphan->product_size_id,
                    (int) $orphan->color_id,
                );
            }
            $this->line('<fg=yellow>Balances huérfanos puestos en cero: '.count($orphans).'</fg=yellow>');
        }

        $rows = $this->findMismatches($productFilter);

        if ($rows === []) {
            $this->line('<fg=green>Inventario cuadrado: no hay discrepancias que corregir.</fg=green>');

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
                (string) $r->product_size_id,
                (string) $r->master_stock,
                (string) $r->color_sum,
                (string) $r->diff,
            ];
        }, $rows);

        $this->table(
            ['Warehouse ID', 'Almacén', 'Product ID', 'Product Size ID', 'Stock Maestro', 'Suma Colores', 'Diferencia'],
            $tableRows,
        );

        if ($dryRun) {
            $this->warn('Modo dry-run: no se aplicaron cambios.');

            return self::FAILURE;
        }

        $fixed = 0;
        foreach ($rows as $row) {
            $this->inventoryMovementService->syncMasterBalanceToColorSum(
                (int) $row->warehouse_id,
                (int) $row->product_size_id,
            );
            $fixed++;
        }

        $this->line("<fg=green>Corregidas {$fixed} talla(s).</fg=green>");

        $remaining = $this->findMismatches($productFilter);

        if ($remaining !== []) {
            $this->error('Aún quedan discrepancias tras la corrección. Revise manualmente.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<object{
     *     warehouse_id: int|string,
     *     warehouse_name: ?string,
     *     product_id: int|string,
     *     product_size_id: int|string,
     *     master_stock: int|string,
     *     color_sum: int|string,
     *     diff: int|string
     * }>
     */
    private function findMismatches(?int $productId): array
    {
        $sql = <<<'SQL'
            SELECT
                k.warehouse_id,
                w.name AS warehouse_name,
                ps.product_id,
                k.product_size_id,
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
                SELECT ib.warehouse_id, ib.product_size_id, SUM(ib.quantity) AS total
                FROM inventory_balances ib
                INNER JOIN product_size_color psc
                    ON psc.product_size_id = ib.product_size_id
                   AND psc.color_id = ib.color_id
                GROUP BY ib.warehouse_id, ib.product_size_id
            ) color
                ON color.warehouse_id = k.warehouse_id
               AND color.product_size_id = k.product_size_id
            WHERE COALESCE(master.total, 0) <> COALESCE(color.total, 0)
            SQL;

        $bindings = [];
        if ($productId !== null && $productId > 0) {
            $sql .= ' AND ps.product_id = ?';
            $bindings[] = $productId;
        }

        $sql .= ' ORDER BY k.warehouse_id ASC, ps.product_id ASC, k.product_size_id ASC';

        return DB::select($sql, $bindings);
    }

    /**
     * @return list<object{
     *     id: int|string,
     *     warehouse_id: int|string,
     *     product_size_id: int|string,
     *     color_id: int|string,
     *     quantity: int|string
     * }>
     */
    private function findOrphanColorBalances(?int $productId): array
    {
        $sql = <<<'SQL'
            SELECT ib.id, ib.warehouse_id, ib.product_size_id, ib.color_id, ib.quantity
            FROM inventory_balances ib
            INNER JOIN product_size ps ON ps.id = ib.product_size_id
            WHERE ib.color_id IS NOT NULL
              AND ib.quantity <> 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM product_size_color psc
                  WHERE psc.product_size_id = ib.product_size_id
                    AND psc.color_id = ib.color_id
              )
            SQL;

        $bindings = [];
        if ($productId !== null && $productId > 0) {
            $sql .= ' AND ps.product_id = ?';
            $bindings[] = $productId;
        }

        $sql .= ' ORDER BY ib.warehouse_id ASC, ib.product_size_id ASC, ib.color_id ASC';

        return DB::select($sql, $bindings);
    }
}

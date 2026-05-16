<?php

namespace App\Console\Commands;

use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\Product\Models\ProductSize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryRepairMasterBalancesCommand extends Command
{
    protected $signature = 'inventory:repair-master-balances {--dry-run : Solo muestra lo que haría sin ejecutar}';

    protected $description = 'Recrea filas maestro (color_id = null) en inventory_balances cuando solo existen filas por color. También cubre tallas sin color que no tengan balance maestro.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 1) Reparar maestros donde existen colores pero falta el maestro
        $missingMasterRows = DB::select(<<<'SQL'
            SELECT
                ib.product_id,
                ib.warehouse_id,
                ib.product_size_id,
                COALESCE(SUM(ib.quantity), 0)::bigint AS total_qty,
                MAX(p.warehouse_id) AS product_warehouse_id,
                MAX(w.tenant_id) AS tenant_id
            FROM inventory_balances ib
            INNER JOIN product_size ps ON ps.id = ib.product_size_id
            INNER JOIN products p ON p.id = ps.product_id
            INNER JOIN warehouses w ON w.id = ib.warehouse_id
            WHERE ib.color_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM inventory_balances ib2
                  WHERE ib2.warehouse_id = ib.warehouse_id
                    AND ib2.product_size_id = ib.product_size_id
                    AND ib2.color_id IS NULL
              )
            GROUP BY ib.product_id, ib.warehouse_id, ib.product_size_id
            SQL);

        $created = 0;
        foreach ($missingMasterRows as $row) {
            if ($dryRun) {
                $this->line("[DRY-RUN] Crear master: product_size_id={$row->product_size_id} warehouse_id={$row->warehouse_id} qty={$row->total_qty}");
                continue;
            }

            InventoryBalance::query()->updateOrInsert(
                [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'product_size_id' => (int) $row->product_size_id,
                    'color_id' => null,
                ],
                [
                    'tenant_id' => (int) $row->tenant_id,
                    'product_id' => (int) $row->product_id,
                    'quantity' => (int) $row->total_qty,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $created++;
        }

        // 2) Reparar tallas sin color que no tengan NINGÚN balance
        // (solo podemos reconstruir si el stock fue registrado por movimientos,
        // pero sin columnas legadas no hay forma de saber el stock original.
        // Esto solo crea balance 0 para evitar nulls en lecturas.)
        $missingAnyBalance = DB::select(<<<'SQL'
            SELECT
                ps.id AS product_size_id,
                ps.product_id,
                p.warehouse_id,
                w.tenant_id
            FROM product_size ps
            INNER JOIN products p ON p.id = ps.product_id
            INNER JOIN warehouses w ON w.id = p.warehouse_id
            WHERE NOT EXISTS (
                SELECT 1 FROM inventory_balances ib
                WHERE ib.product_size_id = ps.id
            )
            AND NOT EXISTS (
                SELECT 1 FROM product_size_color psc
                WHERE psc.product_size_id = ps.id
            )
            SQL);

        $createdZero = 0;
        foreach ($missingAnyBalance as $row) {
            if ($dryRun) {
                $this->line("[DRY-RUN] Crear balance 0: product_size_id={$row->product_size_id} warehouse_id={$row->warehouse_id}");
                continue;
            }

            InventoryBalance::query()->updateOrInsert(
                [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'product_size_id' => (int) $row->product_size_id,
                    'color_id' => null,
                ],
                [
                    'tenant_id' => (int) $row->tenant_id,
                    'product_id' => (int) $row->product_id,
                    'quantity' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $createdZero++;
        }

        $this->info("Maestros reparados: {$created}");
        $this->info("Balances 0 creados para tallas sin color: {$createdZero}");

        return self::SUCCESS;
    }
}

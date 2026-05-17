<?php

namespace App\Inventory\InventoryLedger\Console;

use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MigrateLegacyStockCommand extends Command
{
    protected $signature = 'inventory:migrate-legacy-stock
                            {--warehouse= : ID de almacén por defecto si el producto no tiene warehouse_id}
                            {--force : Ejecutar aunque ya existan filas en inventory_balances (re-sincroniza cantidades desde el legado)}';

    protected $description = 'Copia stock legado de product_size y product_size_color hacia inventory_balances (Política A, sin borrar columnas antiguas).';

    public function handle(): int
    {
        if (! InventoryBalance::query()->exists()) {
            $this->info('Tabla inventory_balances vacía. Iniciando migración…');
        } elseif (! $this->option('force')) {
            $this->error('inventory_balances ya contiene datos. Usa --force para volver a aplicar cantidades desde el legado.');

            return self::FAILURE;
        }

        $defaultWarehouseId = $this->resolveDefaultWarehouseId();

        $fromColors = DB::table('product_size as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->join('product_size_color as psc', 'psc.product_size_id', '=', 'ps.id')
            ->where('psc.stock', '>', 0)
            ->orderBy('ps.id')
            ->orderBy('psc.color_id')
            ->get([
                'ps.id as product_size_id',
                'psc.color_id as color_id',
                'psc.stock as quantity',
                'p.id as product_id',
                'p.warehouse_id as product_warehouse_id',
            ]);

        $fromMasterOnly = DB::table('product_size as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.stock', '>', 0)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('product_size_color as psc')
                    ->whereColumn('psc.product_size_id', 'ps.id');
            })
            ->orderBy('ps.id')
            ->get([
                'ps.id as product_size_id',
                'p.id as product_id',
                'p.warehouse_id as product_warehouse_id',
                'ps.stock as quantity',
            ]);

        $tasks = [];

        foreach ($fromColors as $row) {
            $warehouseId = $this->warehouseIdForProduct(
                $row->product_warehouse_id !== null ? (int) $row->product_warehouse_id : null,
                $defaultWarehouseId,
            );
            $tasks[] = (object) [
                'product_size_id' => (int) $row->product_size_id,
                'color_id' => (int) $row->color_id,
                'quantity' => (int) $row->quantity,
                'product_id' => (int) $row->product_id,
                'warehouse_id' => $warehouseId,
            ];
        }

        foreach ($fromMasterOnly as $row) {
            $warehouseId = $this->warehouseIdForProduct(
                $row->product_warehouse_id !== null ? (int) $row->product_warehouse_id : null,
                $defaultWarehouseId,
            );
            $tasks[] = (object) [
                'product_size_id' => (int) $row->product_size_id,
                'color_id' => null,
                'quantity' => (int) $row->quantity,
                'product_id' => (int) $row->product_id,
                'warehouse_id' => $warehouseId,
            ];
        }

        if ($tasks === []) {
            $this->warn('No hay registros con stock > 0 para migrar.');

            return self::SUCCESS;
        }

        $this->withProgressBar($tasks, function ($task): void {
            DB::transaction(function () use ($task): void {
                $tenantId = $this->tenantIdForWarehouse($task->warehouse_id);

                $attributes = [
                    'warehouse_id' => $task->warehouse_id,
                    'product_size_id' => $task->product_size_id,
                    'color_id' => $task->color_id,
                ];

                $values = [
                    'tenant_id' => $tenantId,
                    'product_id' => $task->product_id,
                    'quantity' => $task->quantity,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];

                InventoryBalance::query()->updateOrInsert($attributes, $values);
            });
        });

        $this->newLine();
        $this->info('Migración de stock legado finalizada. Filas procesadas: '.count($tasks).'.');

        return self::SUCCESS;
    }

    private function resolveDefaultWarehouseId(): int
    {
        $option = $this->option('warehouse');
        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        $id = (int) Warehouse::query()->orderBy('id')->value('id');
        if ($id < 1) {
            throw new \RuntimeException(
                'No hay almacenes en la base de datos. Crea uno o indica --warehouse=<id>.',
            );
        }

        $this->comment("Usando almacén por defecto (primer id): {$id} cuando product.warehouse_id sea nulo.");

        return $id;
    }

    private function warehouseIdForProduct(?int $productWarehouseId, int $defaultWarehouseId): int
    {
        if ($productWarehouseId !== null && $productWarehouseId > 0) {
            return $productWarehouseId;
        }

        return $defaultWarehouseId;
    }

    private function tenantIdForWarehouse(int $warehouseId): int
    {
        $tenantId = Warehouse::query()->whereKey($warehouseId)->value('tenant_id');

        if ($tenantId === null || (int) $tenantId < 1) {
            throw new InvalidArgumentException(
                "El almacén ID [{$warehouseId}] no tiene un tenant_id asignado. Operación abortada por seguridad de datos.",
            );
        }

        return (int) $tenantId;
    }
}

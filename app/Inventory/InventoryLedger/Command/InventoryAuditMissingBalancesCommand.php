<?php

namespace App\Inventory\InventoryLedger\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryAuditMissingBalancesCommand extends Command
{
    protected $signature = 'inventory:audit-missing-balances
                            {--warehouse= : Filtra por warehouse_id específico}
                            {--only-ids : Imprime solo IDs únicos de productos corruptos}';

    protected $description = 'Detecta product_size y product_size_color sin equivalente en inventory_balances.';

    public function handle(): int
    {
        $warehouseFilter = $this->option('warehouse');

        $masterRows = DB::table('product_size as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('inventory_balances as ib', function ($join): void {
                $join->on('ib.product_size_id', '=', 'ps.id')
                    ->on('ib.product_id', '=', 'ps.product_id')
                    ->on('ib.warehouse_id', '=', 'p.warehouse_id')
                    ->whereNull('ib.color_id');
            })
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('product_size_color as psc')
                    ->whereColumn('psc.product_size_id', 'ps.id');
            })
            ->whereNull('ib.id')
            ->when($warehouseFilter, fn($q) => $q->where('p.warehouse_id', (int) $warehouseFilter))
            ->select([
                DB::raw("'master_size' as issue_type"),
                'p.id as product_id',
                'p.warehouse_id',
                'ps.id as product_size_id',
                'ps.size_id',
                DB::raw('NULL as color_id'),
            ]);

        $colorRows = DB::table('product_size_color as psc')
            ->join('product_size as ps', 'ps.id', '=', 'psc.product_size_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('inventory_balances as ib', function ($join): void {
                $join->on('ib.product_size_id', '=', 'ps.id')
                    ->on('ib.product_id', '=', 'ps.product_id')
                    ->on('ib.warehouse_id', '=', 'p.warehouse_id')
                    ->on('ib.color_id', '=', 'psc.color_id');
            })
            ->whereNull('ib.id')
            ->when($warehouseFilter, fn($q) => $q->where('p.warehouse_id', (int) $warehouseFilter))
            ->select([
                DB::raw("'color_variant' as issue_type"),
                'p.id as product_id',
                'p.warehouse_id',
                'ps.id as product_size_id',
                'ps.size_id',
                'psc.color_id',
            ]);

        $rows = $masterRows
            ->unionAll($colorRows)
            ->orderBy('product_id')
            ->orderBy('product_size_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('<fg=green>No se detectaron balances faltantes.</fg=green>');

            return self::SUCCESS;
        }

        $productIds = $rows->pluck('product_id')->unique()->values();

        if ($this->option('only-ids')) {
            $this->line($productIds->implode(','));

            return self::FAILURE;
        }

        $this->warn('Productos con balances faltantes: ' . $productIds->implode(','));

        $this->table(
            ['Issue', 'Product ID', 'Warehouse ID', 'Product Size ID', 'Size ID', 'Color ID'],
            $rows->map(static fn($row): array => [
                $row->issue_type,
                (string) $row->product_id,
                (string) $row->warehouse_id,
                (string) $row->product_size_id,
                (string) $row->size_id,
                $row->color_id === null ? 'NULL' : (string) $row->color_id,
            ])->all(),
        );

        return self::FAILURE;
    }
}

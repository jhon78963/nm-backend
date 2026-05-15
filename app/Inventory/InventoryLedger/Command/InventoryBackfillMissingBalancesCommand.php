<?php

namespace App\Inventory\InventoryLedger\Command;

use App\Administration\Tenant\Models\Tenant;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryBackfillMissingBalancesCommand extends Command
{
    protected $signature = 'inventory:backfill-missing-balances
                            {--warehouse= : Filtra por warehouse_id específico}
                            {--dry-run : Solo muestra cuántos balances se crearían, sin insertar}
                            {--chunk=500 : Cantidad de filas a insertar por lote}';

    protected $description = 'Crea balances Kardex faltantes en quantity 0 para product_size y product_size_color existentes.';

    public function handle(): int
    {
        $warehouseFilter = $this->option('warehouse');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $now = now();

        $rows = $this->missingBalanceRows($warehouseFilter)->get();

        if ($rows->isEmpty()) {
            $this->line('<fg=green>No hay balances faltantes para crear.</fg=green>');

            return self::SUCCESS;
        }

        $this->warn('Balances faltantes encontrados: '.$rows->count());
        $this->line('Productos afectados: '.$rows->pluck('product_id')->unique()->values()->implode(','));

        if ($dryRun) {
            $this->line('<fg=yellow>Dry-run activo: no se insertó ningún balance.</fg=yellow>');

            return self::SUCCESS;
        }

        $tenantByWarehouse = [];
        $fallbackTenantId = null;
        $created = 0;

        foreach ($rows->chunk($chunkSize) as $chunk) {
            $payload = [];

            foreach ($chunk as $row) {
                $warehouseId = (int) $row->warehouse_id;
                $tenantByWarehouse[$warehouseId] ??= $this->tenantIdForWarehouse($warehouseId, $fallbackTenantId);

                $payload[] = [
                    'tenant_id' => $tenantByWarehouse[$warehouseId],
                    'warehouse_id' => $warehouseId,
                    'product_id' => (int) $row->product_id,
                    'product_size_id' => (int) $row->product_size_id,
                    'color_id' => $row->color_id === null ? null : (int) $row->color_id,
                    'quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('inventory_balances')->insertOrIgnore($payload);
            $created += count($payload);
        }

        $this->line('<fg=green>Backfill finalizado. Balances procesados: '.$created.'.</fg=green>');

        return self::SUCCESS;
    }

    private function missingBalanceRows(?string $warehouseFilter)
    {
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
            ->whereNotNull('p.warehouse_id')
            ->when($warehouseFilter, fn ($q) => $q->where('p.warehouse_id', (int) $warehouseFilter))
            ->select([
                'p.id as product_id',
                'p.warehouse_id',
                'ps.id as product_size_id',
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
            ->whereNotNull('p.warehouse_id')
            ->when($warehouseFilter, fn ($q) => $q->where('p.warehouse_id', (int) $warehouseFilter))
            ->select([
                'p.id as product_id',
                'p.warehouse_id',
                'ps.id as product_size_id',
                'psc.color_id',
            ]);

        return $masterRows
            ->unionAll($colorRows)
            ->orderBy('product_id')
            ->orderBy('product_size_id');
    }

    private function tenantIdForWarehouse(int $warehouseId, ?int &$fallbackTenantId): int
    {
        $tenantId = Warehouse::query()->whereKey($warehouseId)->value('tenant_id');

        if ($tenantId !== null && (int) $tenantId > 0) {
            return (int) $tenantId;
        }

        $fallbackTenantId ??= (int) Tenant::query()->orderBy('id')->value('id');
        if ($fallbackTenantId < 1) {
            throw new \RuntimeException(
                "No se pudo resolver tenant_id para warehouse_id={$warehouseId} ni hay tenant por defecto.",
            );
        }

        return $fallbackTenantId;
    }
}

<?php

namespace App\Inventory\InventoryLedger\Command;

use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedInitialInventoryMovementsCommand extends Command
{
    protected $signature = 'inventory:seed-initial-movements
                            {--chunk=500 : Cantidad de balances a procesar por lote}
                            {--dry-run : Solo muestra conteos, sin insertar movimientos}
                            {--force : Permitir ejecutar aunque inventory_movements ya tenga filas}';

    protected $description = 'Genera movimientos INITIAL_INVENTORY (dirección IN / entrada) por cada balance con quantity > 0, típico tras poblar inventory_balances desde legado.';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (InventoryMovement::query()->exists() && ! $force) {
            $this->error('inventory_movements ya contiene registros. Usa --force si deseas añadir más movimientos iniciales (puede duplicar saldos en reportes si no filtras por fecha/tipo).');

            return self::FAILURE;
        }

        $total = InventoryBalance::query()->where('quantity', '>', 0)->count();

        if ($total === 0) {
            $this->warn('No hay balances con quantity > 0; no se creó ningún movimiento.');

            return self::SUCCESS;
        }

        $this->info("Balances con stock positivo: {$total}");

        if ($dryRun) {
            $this->line('<fg=yellow>Dry-run: no se insertaron movimientos.</fg=yellow>');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $occurredAt = now();
        $inserted = 0;

        InventoryBalance::query()
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($balances) use ($bar, $occurredAt, &$inserted): void {
                $rows = [];

                foreach ($balances as $balance) {
                    /** @var InventoryBalance $balance */
                    $qty = (int) $balance->quantity;

                    $rows[] = [
                        'tenant_id' => (int) $balance->tenant_id,
                        'warehouse_id' => (int) $balance->warehouse_id,
                        'product_size_id' => (int) $balance->product_size_id,
                        'color_id' => $balance->color_id === null ? null : (int) $balance->color_id,
                        'direction' => InventoryMovementDirection::In->value,
                        'quantity' => $qty,
                        'movement_type' => InventoryMovementType::InitialInventory->value,
                        'reference_type' => InventoryBalance::class,
                        'reference_id' => (int) $balance->id,
                        'balance_after_movement' => $qty,
                        'occurred_at' => $occurredAt,
                        'created_by_user_id' => null,
                    ];
                }

                DB::transaction(function () use ($rows, &$inserted): void {
                    DB::table('inventory_movements')->insert($rows);
                    $inserted += count($rows);
                });

                $bar->advance($balances->count());
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Movimientos de inventario inicial insertados: {$inserted}.");

        return self::SUCCESS;
    }
}

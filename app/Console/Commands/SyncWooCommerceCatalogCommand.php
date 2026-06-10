<?php

namespace App\Console\Commands;

use App\Inventory\WooCommerce\Services\WooCommerceSyncService;
use Illuminate\Console\Command;

class SyncWooCommerceCatalogCommand extends Command
{
    protected $signature = 'woocommerce:sync-catalog
                            {--product-id= : Sincronizar un solo producto nm-backend}
                            {--dry-run : Simular sin llamar a la API de WooCommerce}
                            {--force : Forzar sync aunque no haya cambios detectados}';

    protected $description = 'Sincroniza productos variables (talla × color) y stock hacia WooCommerce.';

    public function __construct(
        private readonly WooCommerceSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $productId = $this->option('product-id');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $productFilter = $productId !== null && $productId !== '' ? (int) $productId : null;

        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribirá en WooCommerce.');
        }

        if ($force) {
            $this->comment('Modo force: se ignorará el checksum de productos ya sincronizados.');
        }

        try {
            $total = $this->syncService->countSyncableProducts($productFilter);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($total < 1) {
            $this->warn('No hay productos para sincronizar en el almacén configurado.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('iniciando…');
        $bar->start();

        try {
            $stats = $this->syncService->syncCatalog(
                $productFilter,
                $dryRun,
                $force,
                function (string $message) use ($bar): void {
                    $bar->setMessage($message);
                    $bar->advance();
                },
            );
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Sync OK — productos: %d, variaciones: %d, omitidos: %d, errores: %d',
            $stats['products'],
            $stats['variations'],
            $stats['skipped'],
            $stats['errors'],
        ));

        if (($stats['failed_product_ids'] ?? []) !== []) {
            $this->warn('Productos con error: '.implode(', ', $stats['failed_product_ids']));
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Inventory\WooCommerce\Services\WooCommerceSyncService;
use Illuminate\Console\Command;

class SyncWooCommerceCatalogCommand extends Command
{
    protected $signature = 'woocommerce:sync-catalog
                            {--product-id= : Sincronizar un solo producto nm-backend}
                            {--dry-run : Simular sin llamar a la API de WooCommerce}';

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
        $productFilter = $productId !== null && $productId !== '' ? (int) $productId : null;

        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribirá en WooCommerce.');
        }

        try {
            $stats = $this->syncService->syncCatalog($productFilter, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sync OK — productos: %d, variaciones: %d, errores: %d',
            $stats['products'],
            $stats['variations'],
            $stats['errors'],
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

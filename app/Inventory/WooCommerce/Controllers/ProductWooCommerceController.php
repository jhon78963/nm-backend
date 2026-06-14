<?php

namespace App\Inventory\WooCommerce\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\WooCommerce\Models\WooCommerceSyncMap;
use App\Inventory\WooCommerce\Services\WooCommerceSyncService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProductWooCommerceController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly WooCommerceSyncService $wooCommerceSyncService,
    ) {}

    public function sync(Product $product): JsonResponse
    {
        $this->productService->validate($product, 'Product');

        if (! config('woocommerce.enabled')) {
            return response()->json([
                'message' => 'WooCommerce sync desactivado.',
                'wooCommerceSync' => [
                    'attempted' => false,
                    'products' => 0,
                    'variations' => 0,
                    'errors' => 0,
                    'error' => 'WOO_SYNC_ENABLED=false.',
                ],
                'wooProductId' => null,
                'lastSyncedAt' => null,
            ], 207);
        }

        try {
            $stats = $this->wooCommerceSyncService->syncProductById((int) $product->id);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error sincronizando con WooCommerce.',
                'wooCommerceSync' => [
                    'attempted' => true,
                    'products' => 0,
                    'variations' => 0,
                    'errors' => 1,
                    'error' => $e->getMessage(),
                ],
                'wooProductId' => null,
                'lastSyncedAt' => null,
            ], 207);
        }

        $map = WooCommerceSyncMap::query()
            ->where('variant_key', "p:{$product->id}")
            ->first();

        $sync = [
            'attempted' => true,
            'products' => $stats['products'],
            'variations' => $stats['variations'],
            'errors' => $stats['errors'],
            'error' => $this->resolveSyncErrorMessage($stats),
        ];

        return response()->json([
            'message' => $sync['errors'] > 0 || $sync['products'] < 1
                ? 'Sincronización incompleta con WooCommerce.'
                : 'Producto sincronizado con WooCommerce.',
            'wooCommerceSync' => $sync,
            'wooProductId' => ($map?->woo_product_id ?? 0) > 0 ? (int) $map->woo_product_id : null,
            'lastSyncedAt' => $map?->last_synced_at?->toIso8601String(),
        ], $this->syncHttpStatus($sync));
    }

    /**
     * @param  array{products: int, variations: int, errors: int}  $stats
     */
    private function resolveSyncErrorMessage(array $stats): ?string
    {
        if (($stats['errors'] ?? 0) > 0) {
            return 'WooCommerce sync finished with errors. Check logs.';
        }

        if (($stats['products'] ?? 0) < 1) {
            return 'Producto no sincronizado: verifica almacén WooCommerce, variantes talla×color y configuración.';
        }

        return null;
    }

    /**
     * @param  array{attempted: bool, products: int, errors: int}  $sync
     */
    private function syncHttpStatus(array $sync): int
    {
        if (! $sync['attempted']) {
            return 207;
        }

        if (($sync['errors'] ?? 0) > 0 || ($sync['products'] ?? 0) < 1) {
            return 207;
        }

        return 200;
    }
}

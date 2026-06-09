<?php

namespace App\Inventory\WooCommerce\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Support\EcommerceCatalogScope;
use App\Inventory\WooCommerce\Models\WooCommerceSyncMap;
use App\Inventory\WooCommerce\Services\WooCommerceImageSideloader;
use App\Inventory\WooCommerce\Support\WooCommerceCatalogBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class WooCommerceSyncService
{
    use EcommerceCatalogScope;

    public function __construct(
        private readonly WooCommerceCatalogBuilder $catalogBuilder,
        private readonly WooCommerceImageSideloader $imageSideloader,
    ) {}

    /**
     * @return array{products: int, variations: int, errors: int}
     */
    public function syncCatalog(?int $productId = null, bool $dryRun = false): array
    {
        $this->assertConfigured();

        $warehouseId = (int) config('woocommerce.warehouse_id');
        if ($warehouseId < 1) {
            throw new RuntimeException('WOO_SYNC_WAREHOUSE_ID no configurado o inválido.');
        }

        $stats = ['products' => 0, 'variations' => 0, 'errors' => 0];

        $query = $this->ecommerceProductQuery($warehouseId)
            ->with($this->ecommerceWith($warehouseId))
            ->orderBy('id');

        if ($productId !== null) {
            $query->whereKey($productId);
        }

        $query->chunkById(config('woocommerce.batch_size', 50), function ($products) use (&$stats, $warehouseId, $dryRun): void {
            /** @var Product $product */
            foreach ($products as $product) {
                try {
                    $payload = $this->catalogBuilder->buildProductPayload($product, $warehouseId);
                    if ($payload === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $stats['products']++;
                        $stats['variations'] += count($payload['variations']);

                        continue;
                    }

                    $result = $this->syncProduct($payload);
                    $stats['products']++;
                    $stats['variations'] += $result['variations'];
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error('WooCommerce sync error', [
                        'product_id' => $product->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $stats;
    }

    /**
     * Sincroniza un único producto hacia WooCommerce (p. ej. tras subir imágenes).
     *
     * @return array{products: int, variations: int, errors: int}
     */
    public function syncProductById(int $productId): array
    {
        return $this->syncCatalog($productId, false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{woo_product_id: int, variations: int}
     */
    private function syncProduct(array $payload): array
    {
        $nmProductId = (int) $payload['product_id'];
        $variations = $payload['variations'];
        $images = $this->resolveProductImages($payload);
        unset($payload['variations'], $payload['product_id'], $payload['image_paths'], $payload['gallery_urls']);

        $existingMap = WooCommerceSyncMap::query()
            ->where('variant_key', "p:{$nmProductId}")
            ->first();

        $wooProductId = $existingMap?->woo_product_id;
        $existingWooProductId = ($wooProductId !== null && $wooProductId > 0) ? $wooProductId : null;

        if ($existingWooProductId) {
            $response = $this->client()->put("products/{$existingWooProductId}", $this->productBody($payload, $images));
            $wooProductId = $existingWooProductId;
        } else {
            $response = $this->client()->post('products', $this->productBody($payload, $images));
        }

        $this->throwIfFailed($response, "product {$nmProductId}");

        if (! $existingWooProductId) {
            $wooProductId = (int) $response->json('id');
            if ($wooProductId < 1) {
                throw new RuntimeException("WooCommerce no devolvió ID de producto válido (product {$nmProductId}). ¿Permalinks activos en WordPress?");
            }

            $this->upsertMap($nmProductId, null, null, $wooProductId, null, "p:{$nmProductId}");
        }

        $syncedVariations = 0;
        foreach ($variations as $variationPayload) {
            $this->syncVariation($wooProductId, $variationPayload);
            $syncedVariations++;
        }

        return ['woo_product_id' => $wooProductId, 'variations' => $syncedVariations];
    }

    /**
     * @param  array<string, mixed>  $variationPayload
     */
    private function syncVariation(int $wooProductId, array $variationPayload): void
    {
        $variantKey = (string) $variationPayload['variant_key'];
        $map = WooCommerceSyncMap::query()->where('variant_key', $variantKey)->first();

        $body = [
            'sku' => $variationPayload['sku'],
            'regular_price' => $variationPayload['regular_price'],
            'manage_stock' => true,
            'stock_quantity' => $variationPayload['stock_quantity'],
            'status' => 'publish',
            'attributes' => collect($variationPayload['attributes'])
                ->map(static fn (string $option, string $name): array => [
                    'name' => $name,
                    'option' => $option,
                ])
                ->values()
                ->all(),
            'meta_data' => [
                ['key' => config('woocommerce.meta.variant_key'), 'value' => $variantKey],
                ['key' => config('woocommerce.meta.product_size_id'), 'value' => (string) $variationPayload['product_size_id']],
                ['key' => config('woocommerce.meta.color_id'), 'value' => (string) $variationPayload['color_id']],
            ],
        ];

        if ($map?->woo_variation_id) {
            $response = $this->client()->put(
                "products/{$wooProductId}/variations/{$map->woo_variation_id}",
                $body,
            );
            $wooVariationId = (int) $map->woo_variation_id;
        } else {
            $response = $this->client()->post("products/{$wooProductId}/variations", $body);
            $wooVariationId = (int) $response->json('id');
        }

        $this->throwIfFailed($response, "variation {$variantKey}");

        if ($wooVariationId < 1) {
            throw new RuntimeException("WooCommerce no devolvió ID de variación válido ({$variantKey}).");
        }

        $this->upsertMap(
            (int) $variationPayload['product_id'],
            (int) $variationPayload['product_size_id'],
            (int) $variationPayload['color_id'],
            $wooProductId,
            $wooVariationId,
            $variantKey,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function resolveProductImages(array $payload): array
    {
        $imagePaths = $payload['image_paths'] ?? [];
        $urlImages = $payload['images'] ?? [];

        if ($imagePaths === [] && $urlImages === []) {
            return [];
        }

        if (
            config('woocommerce.image_sideload', true)
            && $imagePaths !== []
            && $this->imageSideloader->isConfigured()
        ) {
            return $this->imageSideloader->sideloadGallery(
                $imagePaths,
                Str::limit((string) ($payload['name'] ?? ''), 120, ''),
            );
        }

        return $urlImages;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    private function productBody(array $payload, array $images): array
    {
        $body = [
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'type' => 'variable',
            'status' => $payload['status'],
            'description' => $payload['description'],
            'short_description' => $payload['short_description'],
            'sku' => $payload['sku'],
            'attributes' => $payload['attributes'],
            'meta_data' => $payload['meta_data'],
        ];

        if ($images !== []) {
            $body['images'] = $images;
        } else {
            // Vacía la galería en WooCommerce cuando no quedan imágenes en nm-backend.
            $body['images'] = [];
        }

        return $body;
    }

    private function upsertMap(
        int $productId,
        ?int $productSizeId,
        ?int $colorId,
        int $wooProductId,
        ?int $wooVariationId,
        ?string $variantKey,
    ): void {
        WooCommerceSyncMap::query()->updateOrCreate(
            ['variant_key' => $variantKey ?? "p:{$productId}"],
            [
                'product_id' => $productId,
                'product_size_id' => $productSizeId,
                'color_id' => $colorId,
                'woo_product_id' => $wooProductId,
                'woo_variation_id' => $wooVariationId,
                'last_synced_at' => now(),
            ],
        );
    }

    private function client(): PendingRequest
    {
        $baseUrl = rtrim((string) config('woocommerce.base_url'), '/');

        return Http::baseUrl("{$baseUrl}/wp-json/wc/v3/")
            ->withBasicAuth(
                (string) config('woocommerce.consumer_key'),
                (string) config('woocommerce.consumer_secret'),
            )
            ->acceptJson()
            ->timeout((int) config('woocommerce.timeout', 30))
            ->when(
                ! config('woocommerce.verify_ssl', true),
                static fn (PendingRequest $request) => $request->withoutVerifying(),
            );
    }

    private function assertConfigured(): void
    {
        if (! config('woocommerce.enabled')) {
            throw new RuntimeException('WOO_SYNC_ENABLED=false. Actívalo en .env para sincronizar.');
        }

        foreach (['base_url', 'consumer_key', 'consumer_secret'] as $key) {
            if (blank(config("woocommerce.{$key}"))) {
                throw new RuntimeException("Falta configuración woocommerce.{$key} en .env");
            }
        }
    }

    private function throwIfFailed(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'WooCommerce API error (%s): HTTP %s — %s',
            $context,
            $response->status(),
            $response->body(),
        ));
    }
}

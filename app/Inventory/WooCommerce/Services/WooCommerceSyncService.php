<?php

namespace App\Inventory\WooCommerce\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Support\EcommerceCatalogScope;
use App\Inventory\WooCommerce\Models\WooCommerceSyncMap;
use App\Inventory\WooCommerce\Support\WooCommerceCatalogBuilder;
use App\Inventory\WooCommerce\Support\WooCommerceSyncChecksum;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
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
        private readonly WooCommerceTaxonomyResolver $taxonomyResolver,
    ) {}

    public function countSyncableProducts(?int $productId = null): int
    {
        $warehouseId = (int) config('woocommerce.warehouse_id');

        $query = $this->ecommerceProductQuery($warehouseId);

        if ($productId !== null) {
            $query->whereKey($productId);
        }

        return $query->count();
    }

    /**
     * @param  callable(string $message): void|null  $onProgress
     * @return array{products: int, variations: int, errors: int, skipped: int, failed_product_ids: list<int>}
     */
    public function syncCatalog(
        ?int $productId = null,
        bool $dryRun = false,
        bool $force = false,
        ?callable $onProgress = null,
    ): array {
        $this->assertConfigured();

        $warehouseId = (int) config('woocommerce.warehouse_id');
        if ($warehouseId < 1) {
            throw new RuntimeException('WOO_SYNC_WAREHOUSE_ID no configurado o inválido.');
        }

        $stats = [
            'products' => 0,
            'variations' => 0,
            'errors' => 0,
            'skipped' => 0,
            'failed_product_ids' => [],
        ];

        $query = $this->ecommerceProductQuery($warehouseId)
            ->with($this->ecommerceWith($warehouseId))
            ->orderBy('id');

        if ($productId !== null) {
            $query->whereKey($productId);
        }

        $query->chunkById(config('woocommerce.batch_size', 50), function ($products) use (
            &$stats,
            $warehouseId,
            $dryRun,
            $force,
            $onProgress,
        ): void {
            $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $mapsByVariantKey = $this->preloadMaps($productIds);

            /** @var Product $product */
            foreach ($products as $product) {
                $label = "#{$product->id} ".Str::limit((string) $product->name, 40, '…');

                try {
                    $payload = $this->catalogBuilder->buildProductPayload($product, $warehouseId);
                    if ($payload === null) {
                        $this->reportProgress($onProgress, "Omitido {$label} (sin variantes)");
                        continue;
                    }

                    if ($dryRun) {
                        $stats['products']++;
                        $stats['variations'] += count($payload['variations']);
                        $this->reportProgress($onProgress, "Simulado {$label}");

                        continue;
                    }

                    $checksum = WooCommerceSyncChecksum::fromPayload($payload);
                    $parentMap = $mapsByVariantKey->get("p:{$product->id}");

                    if (! $force && $this->shouldSkip($parentMap, $checksum)) {
                        $stats['skipped']++;
                        $this->reportProgress($onProgress, "Sin cambios {$label}");

                        continue;
                    }

                    $this->reportProgress($onProgress, "Sincronizando {$label}");
                    $result = $this->syncProduct($payload, $mapsByVariantKey, $checksum);
                    $stats['products']++;
                    $stats['variations'] += $result['variations'];
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $stats['failed_product_ids'][] = (int) $product->id;
                    Log::error('WooCommerce sync error', [
                        'product_id' => $product->id,
                        'message' => $e->getMessage(),
                    ]);
                    $this->reportProgress($onProgress, "Error {$label}");
                }
            }
        });

        return $stats;
    }

    /**
     * @return array{products: int, variations: int, errors: int, skipped: int, failed_product_ids: list<int>}
     */
    public function syncProductById(int $productId, bool $force = true): array
    {
        return $this->syncCatalog($productId, false, $force);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{woo_product_id: int, variations: int}
     */
    private function syncProduct(
        array $payload,
        Collection $mapsByVariantKey,
        string $checksum,
    ): array {
        $nmProductId = (int) $payload['product_id'];
        $variations = $payload['variations'];
        $imagePaths = $payload['image_paths'] ?? [];
        $imagePathsChecksum = hash('sha256', json_encode($imagePaths, JSON_THROW_ON_ERROR));
        $parentMap = $mapsByVariantKey->get("p:{$nmProductId}");
        $galleryChanged = $parentMap === null
            || ($parentMap->woo_product_id ?? 0) < 1
            || ! hash_equals((string) ($parentMap->image_paths_checksum ?? ''), $imagePathsChecksum);
        $images = $this->resolveProductImages(
            $payload,
            $parentMap,
            $imagePathsChecksum,
        );
        unset($payload['variations'], $payload['product_id'], $payload['image_paths'], $payload['gallery_urls']);

        $existingWooProductId = ($parentMap?->woo_product_id ?? 0) > 0 ? (int) $parentMap->woo_product_id : null;

        if ($existingWooProductId) {
            $response = $this->client()->put(
                "products/{$existingWooProductId}",
                $this->productBody($payload, $images, $parentMap, $checksum, $galleryChanged),
            );
            $wooProductId = $existingWooProductId;
        } else {
            $response = $this->client()->post(
                'products',
                $this->productBody($payload, $images, null, $checksum, true),
            );
        }

        $this->throwIfFailed($response, "product {$nmProductId}");

        if (! $existingWooProductId) {
            $wooProductId = (int) $response->json('id');
            if ($wooProductId < 1) {
                throw new RuntimeException("WooCommerce no devolvió ID de producto válido (product {$nmProductId}). ¿Permalinks activos en WordPress?");
            }

            $this->upsertMap($nmProductId, null, null, $wooProductId, null, "p:{$nmProductId}", $checksum, $imagePathsChecksum);
            $mapsByVariantKey->put("p:{$nmProductId}", WooCommerceSyncMap::query()->where('variant_key', "p:{$nmProductId}")->first());
        } else {
            $this->upsertMap($nmProductId, null, null, $wooProductId, null, "p:{$nmProductId}", $checksum, $imagePathsChecksum);
        }

        $syncedVariations = $this->syncVariationsBatch($wooProductId, $variations, $mapsByVariantKey);

        return ['woo_product_id' => $wooProductId, 'variations' => $syncedVariations];
    }

    /**
     * @param  list<array<string, mixed>>  $variations
     */
    private function syncVariationsBatch(int $wooProductId, array $variations, Collection $mapsByVariantKey): int
    {
        if ($variations === []) {
            return 0;
        }

        $create = [];
        $update = [];
        $createMeta = [];
        $updateMeta = [];

        foreach ($variations as $variationPayload) {
            $variantKey = (string) $variationPayload['variant_key'];
            $body = $this->variationBody($variationPayload);
            $map = $mapsByVariantKey->get($variantKey);

            if (($map?->woo_variation_id ?? 0) > 0) {
                $body['id'] = (int) $map->woo_variation_id;
                $update[] = $body;
                $updateMeta[] = $variationPayload;
            } else {
                $create[] = $body;
                $createMeta[] = $variationPayload;
            }
        }

        $synced = 0;
        $batchSize = (int) config('woocommerce.variation_batch_size', 100);

        $createChunks = array_chunk($create, $batchSize);
        $createMetaChunks = array_chunk($createMeta, $batchSize);
        foreach ($createChunks as $i => $chunk) {
            $synced += $this->dispatchVariationBatch(
                $wooProductId,
                'create',
                $chunk,
                $createMetaChunks[$i] ?? [],
                $mapsByVariantKey,
            );
        }

        $updateChunks = array_chunk($update, $batchSize);
        $updateMetaChunks = array_chunk($updateMeta, $batchSize);
        foreach ($updateChunks as $i => $chunk) {
            $synced += $this->dispatchVariationBatch(
                $wooProductId,
                'update',
                $chunk,
                $updateMetaChunks[$i] ?? [],
                $mapsByVariantKey,
            );
        }

        return $synced;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  list<array<string, mixed>>  $metaChunk
     */
    private function dispatchVariationBatch(
        int $wooProductId,
        string $action,
        array $chunk,
        array $metaChunk,
        Collection $mapsByVariantKey,
    ): int {
        if ($chunk === []) {
            return 0;
        }

        $response = $this->client()->post("products/{$wooProductId}/variations/batch", [
            $action => $chunk,
        ]);

        $this->throwIfFailed($response, "variations batch {$action} product {$wooProductId}");

        $results = $response->json($action) ?? [];
        if (! is_array($results)) {
            throw new RuntimeException("Respuesta batch inválida para producto {$wooProductId}.");
        }

        foreach ($results as $i => $result) {
            if (! is_array($result)) {
                continue;
            }

            $meta = $metaChunk[$i] ?? null;
            if ($meta === null) {
                continue;
            }

            $wooVariationId = (int) ($result['id'] ?? 0);
            if ($wooVariationId < 1) {
                throw new RuntimeException("Variación batch sin ID válido ({$meta['variant_key']}).");
            }

            $variantKey = (string) $meta['variant_key'];
            $this->upsertMap(
                (int) $meta['product_id'],
                (int) $meta['product_size_id'],
                (int) $meta['color_id'],
                $wooProductId,
                $wooVariationId,
                $variantKey,
            );
            $mapsByVariantKey->put($variantKey, WooCommerceSyncMap::query()->where('variant_key', $variantKey)->first());
        }

        return count($results);
    }

    /**
     * @param  array<string, mixed>  $variationPayload
     * @return array<string, mixed>
     */
    private function variationBody(array $variationPayload): array
    {
        $variantKey = (string) $variationPayload['variant_key'];

        return [
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
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function resolveProductImages(
        array $payload,
        ?WooCommerceSyncMap $parentMap,
        string $imagePathsChecksum,
    ): array {
        $imagePaths = $payload['image_paths'] ?? [];
        $urlImages = $payload['images'] ?? [];

        if ($imagePaths === []) {
            return [];
        }

        if (
            ($parentMap?->woo_product_id ?? 0) > 0
            && hash_equals((string) ($parentMap->image_paths_checksum ?? ''), $imagePathsChecksum)
        ) {
            return [];
        }

        if (
            config('woocommerce.image_sideload', true)
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
    private function productBody(
        array $payload,
        array $images,
        ?WooCommerceSyncMap $parentMap,
        string $checksum,
        bool $galleryChanged = true,
    ): array {
        $body = [
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'type' => 'variable',
            'status' => $payload['status'],
            'description' => $payload['description'],
            'short_description' => $payload['short_description'],
            'sku' => $payload['sku'],
            'attributes' => $this->variableProductAttributes($payload['attributes'] ?? []),
            'meta_data' => $payload['meta_data'],
            'categories' => $this->taxonomyResolver->resolveCategories($payload['category'] ?? null),
            'tags' => $this->taxonomyResolver->resolveTags($payload['tags'] ?? []),
        ];

        if ($images !== []) {
            $body['images'] = $images;
        } elseif ($galleryChanged) {
            $body['images'] = [];
        }

        return $body;
    }

    /**
     * Atributos del producto padre: Color y Talla siempre como variación.
     *
     * @param  list<array<string, mixed>>  $attributes
     * @return list<array<string, mixed>>
     */
    private function variableProductAttributes(array $attributes): array
    {
        return collect($attributes)
            ->map(static function (array $attribute): array {
                $attribute['visible'] = true;
                $attribute['variation'] = true;

                return $attribute;
            })
            ->values()
            ->all();
    }

    private function reportProgress(?callable $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    private function shouldSkip(?WooCommerceSyncMap $parentMap, string $checksum): bool
    {
        if ($parentMap === null || ($parentMap->woo_product_id ?? 0) < 1) {
            return false;
        }

        return hash_equals((string) $parentMap->payload_checksum, $checksum);
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<string, WooCommerceSyncMap>
     */
    private function preloadMaps(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return WooCommerceSyncMap::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(static fn (WooCommerceSyncMap $map): string => (string) $map->variant_key);
    }

    private function upsertMap(
        int $productId,
        ?int $productSizeId,
        ?int $colorId,
        int $wooProductId,
        ?int $wooVariationId,
        ?string $variantKey,
        ?string $payloadChecksum = null,
        ?string $imagePathsChecksum = null,
    ): void {
        $data = [
            'product_id' => $productId,
            'product_size_id' => $productSizeId,
            'color_id' => $colorId,
            'woo_product_id' => $wooProductId,
            'woo_variation_id' => $wooVariationId,
            'last_synced_at' => now(),
        ];

        if ($payloadChecksum !== null) {
            $data['payload_checksum'] = $payloadChecksum;
        }

        if ($imagePathsChecksum !== null) {
            $data['image_paths_checksum'] = $imagePathsChecksum;
        }

        WooCommerceSyncMap::query()->updateOrCreate(
            ['variant_key' => $variantKey ?? "p:{$productId}"],
            $data,
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
            ->timeout((int) config('woocommerce.timeout', 120))
            ->retry(2, 3000, static function (\Throwable $e): bool {
                return str_contains($e->getMessage(), 'timed out')
                    || str_contains($e->getMessage(), 'cURL error 28');
            })
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

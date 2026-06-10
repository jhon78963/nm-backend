<?php

namespace App\Inventory\WooCommerce\Support;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\Product\Models\Product;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use App\Inventory\WooCommerce\Support\WooCommerceSyncMapKey;
use Illuminate\Support\Str;

/**
 * Construye el payload de catálogo listo para WooCommerce (producto variable + variaciones).
 */
final class WooCommerceCatalogBuilder
{
    public function __construct(
        private readonly ProductMediaUrlResolver $mediaUrlResolver,
    ) {}

    /**
     * @return array<string, mixed>|null null si el producto no tiene variantes publicables
     */
    public function buildProductPayload(Product $product, int $warehouseId): ?array
    {
        $balanceMap = $this->balanceMapForWarehouse($product, $warehouseId);
        $variations = [];
        $colorOptions = [];
        $sizeOptions = [];
        $minSalePrice = null;

        foreach ($product->productSizes as $productSize) {
            $sizeLabel = trim((string) ($productSize->size->description ?? ''));
            if ($sizeLabel !== '' && ! in_array($sizeLabel, $sizeOptions, true)) {
                $sizeOptions[] = $sizeLabel;
            }

            $variantSalePrice = (float) ($productSize->sale_price ?? 0);
            if ($variantSalePrice > 0 && ($minSalePrice === null || $variantSalePrice < $minSalePrice)) {
                $minSalePrice = $variantSalePrice;
            }

            foreach ($productSize->colors as $color) {
                $colorLabel = trim((string) ($color->description ?? ''));
                if ($colorLabel === '' || $sizeLabel === '') {
                    continue;
                }

                if (! in_array($colorLabel, $colorOptions, true)) {
                    $colorOptions[] = $colorLabel;
                }

                $stock = $balanceMap[InventoryBalanceLookup::key((int) $productSize->id, (int) $color->id)] ?? 0;
                $sku = $this->variantSku($product, $productSize, $color);

                $variations[] = [
                    'variant_key' => WooCommerceSyncMapKey::make((int) $product->id, (int) $productSize->id, (int) $color->id),
                    'product_id' => (int) $product->id,
                    'product_size_id' => (int) $productSize->id,
                    'color_id' => (int) $color->id,
                    'sku' => $sku,
                    'regular_price' => $this->formatPrice($variantSalePrice),
                    'stock_quantity' => max(0, $stock),
                    'manage_stock' => true,
                    'attributes' => [
                        config('woocommerce.attributes.color', 'Color') => $colorLabel,
                        config('woocommerce.attributes.size', 'Talla') => $sizeLabel,
                    ],
                ];
            }
        }

        if ($variations === []) {
            return null;
        }

        $slug = Str::slug($product->name.'-'.$product->id);
        $images = $this->mediaUrlResolver->wooCommerceImagesForProduct($product);
        $imagePaths = $this->mediaUrlResolver->galleryPathsForProduct($product);
        $status = $this->mapStatus($product, $imagePaths);
        $genderName = trim((string) ($product->gender?->name ?? ''));

        return [
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'slug' => $slug,
            'type' => 'variable',
            'status' => $status,
            'description' => strip_tags((string) ($product->description ?? '')),
            'short_description' => Str::limit(strip_tags((string) ($product->description ?? '')), 100),
            'sku' => $this->parentSku($product),
            'regular_price' => $this->formatPrice($minSalePrice ?? 0),
            'images' => $images,
            'image_paths' => $imagePaths,
            'gallery_urls' => array_column($images, 'src'),
            'category' => $genderName !== '' ? [
                'gender_id' => (int) $product->gender_id,
                'name' => $genderName,
            ] : null,
            'tags' => $this->resolveProductKeywords($product),
            'attributes' => [
                [
                    'name' => config('woocommerce.attributes.color', 'Color'),
                    'visible' => true,
                    'variation' => true,
                    'options' => $colorOptions,
                ],
                [
                    'name' => config('woocommerce.attributes.size', 'Talla'),
                    'visible' => true,
                    'variation' => true,
                    'options' => $sizeOptions,
                ],
            ],
            'variations' => $variations,
            'meta_data' => [
                [
                    'key' => config('woocommerce.meta.product_id', '_nm_product_id'),
                    'value' => (string) $product->id,
                ],
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function balanceMapForWarehouse(Product $product, int $warehouseId): array
    {
        if ($product->relationLoaded('inventoryBalances')) {
            $out = [];
            foreach ($product->inventoryBalances as $balance) {
                if ((int) $balance->warehouse_id !== $warehouseId) {
                    continue;
                }

                $colorId = $balance->color_id !== null ? (int) $balance->color_id : null;
                $out[InventoryBalanceLookup::key((int) $balance->product_size_id, $colorId)] = (int) $balance->quantity;
            }

            return $out;
        }

        return InventoryBalanceLookup::mapForProductWarehouse((int) $product->id, $warehouseId);
    }

    private function variantSku(Product $product, $productSize, $color): string
    {
        $barcode = trim((string) ($productSize->barcode ?? ''));

        // Incluir product_size_id: el mismo barcode se repite en varias tallas.
        if ($barcode !== '') {
            return sprintf('%s-%d-%d', $barcode, $productSize->id, $color->id);
        }

        return sprintf('NM-%d-%d-%d', $product->id, $productSize->id, $color->id);
    }

    private function parentSku(Product $product): string
    {
        $barcode = trim((string) ($product->barcode ?? ''));

        if ($barcode !== '') {
            return sprintf('NM-P%d-%s', $product->id, $barcode);
        }

        return "NM-P{$product->id}";
    }

    /**
     * Palabras clave editoriales (Tags). Colores/tallas/estado NO van aquí.
     *
     * @return list<string>
     */
    private function resolveProductKeywords(Product $product): array
    {
        $keywords = config('woocommerce.product_keywords', []);

        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            $keywords,
        ), static fn (string $tag): bool => $tag !== '')));
    }

    private function formatPrice(float $price): string
    {
        return number_format(max(0, $price), 2, '.', '');
    }

    /**
     * @param  list<string>  $imagePaths
     */
    private function mapStatus(Product $product, array $imagePaths): string
    {
        $status = $product->status?->value ?? (string) $product->status;

        if ($status === 'DISCONTINUED') {
            return 'draft';
        }

        return $imagePaths !== [] ? 'publish' : 'draft';
    }
}

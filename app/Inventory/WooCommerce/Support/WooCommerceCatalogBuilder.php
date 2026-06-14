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
            'tags' => $this->generateTags((string) $product->name, $genderName),
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
     * Genera etiquetas editoriales inteligentes a partir del nombre del producto y la categoría.
     * Tallas y colores NO van aquí; viajan como attributes con variation=true.
     *
     * @return list<string>
     */
    private function generateTags(string $productName, string $categoryName): array
    {
        $name = mb_strtoupper($productName, 'UTF-8');
        $tags = [];

        // --- Tipo de manga ---
        foreach ([
            'M/C'         => ['Manga Corta'],
            'MANGA CORTA' => ['Manga Corta'],
            'M/L'         => ['Manga Larga'],
            'MANGA LARGA' => ['Manga Larga'],
            'M/3/4'       => ['Manga 3/4'],
            'SIN MANGA'   => ['Sin Manga'],
        ] as $keyword => $newTags) {
            if (str_contains($name, $keyword)) {
                array_push($tags, ...$newTags);
            }
        }

        // --- Temporada / material ---
        foreach ([
            'POLAR'       => ['Invierno', 'Abrigador'],
            'PELUCHE'     => ['Invierno', 'Abrigador'],
            'FORRO POLAR' => ['Invierno', 'Abrigador'],
            'LANA'        => ['Invierno', 'Abrigador'],
            'DENIM'       => ['Casual', 'Jean'],
            'JEAN'        => ['Casual', 'Jean'],
            'LINO'        => ['Verano', 'Fresco'],
            'LICRA'       => ['Sport', 'Deportivo'],
            'SPANDEX'     => ['Sport', 'Deportivo'],
        ] as $keyword => $newTags) {
            if (str_contains($name, $keyword)) {
                array_push($tags, ...$newTags);
            }
        }

        // --- Estilo ---
        foreach ([
            'DRILL'      => ['Elegante'],
            'VESTIR'     => ['Elegante'],
            'FORMAL'     => ['Elegante'],
            'SPORT'      => ['Deportivo'],
            'DEPORTIVO'  => ['Deportivo'],
            'CASUAL'     => ['Casual'],
            'PIJAMA'     => ['Pijama'],
            'MATERNIDAD' => ['Maternidad'],
            'PREMAMÁ'    => ['Maternidad'],
        ] as $keyword => $newTags) {
            if (str_contains($name, $keyword)) {
                array_push($tags, ...$newTags);
            }
        }

        // --- Tipo de prenda ---
        foreach ([
            'VESTIDO'  => ['Vestidos'],
            'BLUSA'    => ['Blusas'],
            'CAMISA'   => ['Camisas'],
            'CAMISETA' => ['Camisetas'],
            'PANTALÓN' => ['Pantalones'],
            'PANTALON' => ['Pantalones'],
            'FALDA'    => ['Faldas'],
            'CHAQUETA' => ['Chaquetas'],
            'BUZO'     => ['Buzos'],
            'SUDADERA' => ['Sudaderas'],
            'LEGGINS'  => ['Leggings'],
            'LEGGINGS' => ['Leggings'],
            'MAMELUCO' => ['Mamelucos'],
            'BODY'     => ['Bodies'],
            'CONJUNTO' => ['Conjuntos'],
        ] as $keyword => $newTags) {
            if (str_contains($name, $keyword)) {
                array_push($tags, ...$newTags);
            }
        }

        // --- Tags basadas en categoría (género) ---
        $category = mb_strtoupper(trim($categoryName), 'UTF-8');

        foreach ([
            'NIÑOS'     => ['Moda Infantil', 'Ropa Niños'],
            'NIÑAS'     => ['Moda Infantil', 'Ropa Niñas'],
            'NIÑO'      => ['Moda Infantil', 'Ropa Niños'],
            'NIÑA'      => ['Moda Infantil', 'Ropa Niñas'],
            'BEBÉ'      => ['Moda Bebé', 'Ropa Bebé'],
            'BEBE'      => ['Moda Bebé', 'Ropa Bebé'],
            'MUJERES'   => ['Moda Femenina'],
            'MUJER'     => ['Moda Femenina'],
            'DAMA'      => ['Moda Femenina'],
            'DAMAS'     => ['Moda Femenina'],
            'HOMBRES'   => ['Moda Masculina'],
            'HOMBRE'    => ['Moda Masculina'],
            'CABALLERO' => ['Moda Masculina'],
        ] as $keyword => $newTags) {
            if (str_contains($category, $keyword)) {
                array_push($tags, ...$newTags);
            }
        }

        return array_values(array_unique($tags));
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

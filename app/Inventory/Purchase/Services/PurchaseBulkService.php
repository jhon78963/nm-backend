<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Color\Models\Color;
use App\Inventory\Color\Services\ColorService;
use App\Inventory\Product\Enums\ProductStatus;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Inventory\Purchase\Support\PurchasePayloadResolver;
use App\Inventory\Size\Services\SizeService;
use Illuminate\Support\Facades\DB;

/**
 * Registro masivo de compra: crea catálogo temporal (producto/talla/color) y
 * actualiza stock en `product_size` y `product_size_color`; persiste documento `purchases`.
 */
class PurchaseBulkService
{
    public function __construct(
        protected ProductService $productService,
        protected SizeService $sizeService,
        protected ColorService $colorService,
        protected ProductSizeColorService $productSizeColorService,
        protected PurchasePayloadResolver $resolver,
        protected PurchaseDocumentService $purchaseDocumentService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): int
    {
        $purchase = $payload['purchase'] ?? [];
        $warehouseId = (int) ($purchase['warehouseId'] ?? 1);
        $vendorId = isset($purchase['vendorId']) ? (int) $purchase['vendorId'] : null;
        if ($vendorId !== null && $vendorId < 1) {
            $vendorId = null;
        }

        $productTempMap = [];
        $sizeTempMap = [];
        $colorTempMap = [];

        $purchaseId = 0;

        DB::transaction(function () use ($payload, $warehouseId, $vendorId, &$productTempMap, &$sizeTempMap, &$colorTempMap, &$purchaseId): void {
            $this->upsertCatalogProducts(
                $payload['catalogUpserts']['products'] ?? [],
                $warehouseId,
                $vendorId,
                $productTempMap,
            );
            $this->upsertCatalogSizes($payload['catalogUpserts']['sizes'] ?? [], $sizeTempMap);
            $this->upsertCatalogColors($payload['catalogUpserts']['colors'] ?? [], $colorTempMap);
            $this->applyLines($payload['lines'] ?? [], $productTempMap, $sizeTempMap, $colorTempMap);
            if ($vendorId !== null) {
                $this->assignVendorToPurchaseProducts($vendorId, $payload['lines'] ?? [], $productTempMap);
            }
            $document = $this->purchaseDocumentService->record(
                $payload,
                $warehouseId,
                $vendorId,
                $productTempMap,
                $sizeTempMap,
                $colorTempMap,
            );
            $purchaseId = (int) $document->id;
        });

        return $purchaseId;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $map
     */
    protected function upsertCatalogProducts(array $rows, int $warehouseId, ?int $vendorId, array &$map): void
    {
        foreach ($rows as $row) {
            $tempId = $row['tempId'] ?? null;
            if (! $tempId) {
                continue;
            }
            $data = [
                'name' => $row['name'],
                'gender_id' => (int) $row['genderId'],
                'warehouse_id' => $warehouseId,
                'description' => $row['description'] ?? null,
                'barcode' => $row['barcode'] ?? null,
                'status' => ProductStatus::Available->value,
                'vendor_id' => $vendorId,
            ];
            $product = $this->productService->create($data);
            $map[(string) $tempId] = $product->id;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, int>  $productTempMap
     */
    protected function assignVendorToPurchaseProducts(int $vendorId, array $lines, array $productTempMap): void
    {
        $ids = [];
        foreach ($lines as $line) {
            $ref = is_array($line['productRef'] ?? null) ? $line['productRef'] : [];
            if (($ref['mode'] ?? '') === 'id' && isset($ref['productId'])) {
                $ids[] = (int) $ref['productId'];

                continue;
            }
            $tid = (string) ($ref['tempId'] ?? '');
            if ($tid !== '' && isset($productTempMap[$tid])) {
                $ids[] = (int) $productTempMap[$tid];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return;
        }
        Product::query()
            ->where('is_deleted', false)
            ->whereIn('id', $ids)
            ->update(['vendor_id' => $vendorId]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $map
     */
    protected function upsertCatalogSizes(array $rows, array &$map): void
    {
        foreach ($rows as $row) {
            $tempId = $row['tempId'] ?? null;
            if (! $tempId) {
                continue;
            }
            $size = $this->sizeService->create([
                'description' => $row['description'],
                'size_type_id' => (int) $row['sizeTypeId'],
            ]);
            $map[(string) $tempId] = $size->id;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $map
     */
    protected function upsertCatalogColors(array $rows, array &$map): void
    {
        foreach ($rows as $row) {
            $tempId = $row['tempId'] ?? null;
            if (! $tempId) {
                continue;
            }
            $color = $this->colorService->create([
                'description' => $row['description'],
                'hash' => $row['hash'] ?? null,
            ]);
            $map[(string) $tempId] = $color->id;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, int>  $productTempMap
     * @param  array<string, int>  $sizeTempMap
     * @param  array<string, int>  $colorTempMap
     */
    protected function applyLines(
        array $lines,
        array $productTempMap,
        array $sizeTempMap,
        array $colorTempMap,
    ): void {
        foreach ($lines as $line) {
            $productId = $this->resolver->resolveProductId($line['productRef'] ?? [], $productTempMap);
            $sizeId = $this->resolver->resolveSizeId($line['sizeRef'] ?? [], $sizeTempMap);

            $product = Product::query()->where('is_deleted', false)->findOrFail($productId);

            $productSize = $this->resolver->resolveProductSize($product, $sizeId, $line);

            $colors = array_map(
                fn (mixed $c): array => $this->resolver->normalizeLineColorRow(is_array($c) ? $c : []),
                $line['colors'] ?? [],
            );

            $hasColorKeys = $this->resolver->colorsHaveIds($colors);

            $lineTotalQty = 0;
            foreach ($colors as $c) {
                $lineTotalQty += $c['quantity'];
            }

            if (! $hasColorKeys && count($colors) === 1) {
                $this->incrementProductSizeStock($productSize, $lineTotalQty, $line);
                continue;
            }

            $productSize->loadMissing('product');

            foreach ($colors as $c) {
                if ($c['quantity'] <= 0) {
                    continue;
                }
                $colorId = $c['colorId'];
                if ($colorId === null && $c['tempId'] !== null) {
                    $colorId = $colorTempMap[$c['tempId']] ?? null;
                }
                if ($colorId === null) {
                    continue;
                }
                $this->incrementProductSizeColorStock($productSize, (int) $colorId, $c['quantity']);
            }

            $this->incrementProductSizeStock($productSize, $lineTotalQty, $line);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function incrementProductSizeStock(ProductSize $productSize, int $delta, array $line): void
    {
        $this->mergeProductSizePrices($productSize, $line);
        if ($delta !== 0) {
            $productSize->stock = (int) $productSize->stock + $delta;
        }
        $productSize->save();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function mergeProductSizePrices(ProductSize $productSize, array $line): void
    {
        if (array_key_exists('barcode', $line) && $line['barcode'] !== null && $line['barcode'] !== '') {
            $productSize->barcode = (string) $line['barcode'];
        }
        if (array_key_exists('purchasePrice', $line) && $line['purchasePrice'] !== null) {
            $productSize->purchase_price = (float) $line['purchasePrice'];
        }
        if (array_key_exists('salePrice', $line) && $line['salePrice'] !== null) {
            $productSize->sale_price = (float) $line['salePrice'];
        }
        if (array_key_exists('minSalePrice', $line) && $line['minSalePrice'] !== null) {
            $productSize->min_sale_price = (float) $line['minSalePrice'];
        }
    }

    protected function incrementProductSizeColorStock(ProductSize $productSize, int $colorId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        Color::query()->where('is_deleted', false)->findOrFail($colorId);

        $existing = $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->first();

        $current = (int) ($existing?->pivot?->stock ?? 0);
        $newStock = $current + $delta;

        $this->productSizeColorService->set($productSize, $colorId, ['stock' => $newStock]);

        $productSize->unsetRelation('productSizeColors');
    }
}

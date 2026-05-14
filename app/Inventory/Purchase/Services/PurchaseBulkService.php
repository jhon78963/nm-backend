<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Color\Services\ColorService;
use App\Inventory\Concerns\ProvidesInventoryLockSortKey;
use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\Product\Enums\ProductStatus;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\Purchase\Support\PurchasePayloadResolver;
use App\Inventory\Size\Services\SizeService;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro masivo de compra: crea catálogo temporal (producto/talla/color) y
 * actualiza stock en `product_size` y `product_size_color`; persiste documento `purchases`.
 */
class PurchaseBulkService
{
    use ProvidesInventoryLockSortKey;

    public function __construct(
        protected ProductService $productService,
        protected SizeService $sizeService,
        protected ColorService $colorService,
        protected PurchasePayloadResolver $resolver,
        protected PurchaseDocumentService $purchaseDocumentService,
        protected InventoryMovementService $inventoryMovementService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): int
    {
        $purchase = $payload['purchase'] ?? [];
        $warehouseId = (int) ($purchase['warehouseId'] ?? 1);
        $tenantId = (int) Warehouse::query()->findOrFail($warehouseId)->tenant_id;
        $vendorId = isset($purchase['vendorId']) ? (int) $purchase['vendorId'] : null;
        if ($vendorId !== null && $vendorId < 1) {
            $vendorId = null;
        }

        $productTempMap = [];
        $sizeTempMap = [];
        $colorTempMap = [];

        $purchaseId = 0;

        DB::transaction(function () use ($payload, $warehouseId, $tenantId, $vendorId, &$productTempMap, &$sizeTempMap, &$colorTempMap, &$purchaseId): void {
            $this->upsertCatalogProducts(
                $payload['catalogUpserts']['products'] ?? [],
                $warehouseId,
                $vendorId,
                $productTempMap,
            );
            $this->upsertCatalogSizes($payload['catalogUpserts']['sizes'] ?? [], $sizeTempMap);
            $this->upsertCatalogColors($payload['catalogUpserts']['colors'] ?? [], $colorTempMap);
            $this->applyLines($payload['lines'] ?? [], $productTempMap, $sizeTempMap, $colorTempMap, $warehouseId, $tenantId);
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
     * Suma de cantidades de filas que resuelven a un color Id (persistido o vía tempId en map).
     *
     * @param  array<int, array{colorId: ?int, tempId: ?string, quantity: int}>  $colors
     */
    protected function sumBulkLineQtyAttributedToResolvableColors(array $colors, array $colorTempMap): int
    {
        $total = 0;
        foreach ($colors as $c) {
            if ($c['quantity'] <= 0) {
                continue;
            }

            $colorId = $c['colorId'];
            if ($colorId === null && $c['tempId'] !== null) {
                $colorId = isset($colorTempMap[$c['tempId']]) ? (int) $colorTempMap[$c['tempId']] : null;
            }
            if ($colorId === null) {
                continue;
            }

            $total += (int) $c['quantity'];
        }

        return $total;
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
        int $warehouseId,
        int $tenantId,
    ): void {
        $lines = array_values(array_filter($lines, 'is_array'));
        usort(
            $lines,
            fn (array $a, array $b): int => $this->bulkLineInventoryLockSortKey($a, $productTempMap, $sizeTempMap)
                <=> $this->bulkLineInventoryLockSortKey($b, $productTempMap, $sizeTempMap),
        );

        foreach ($lines as $line) {
            $this->applyStockForLine($line, $warehouseId, $tenantId, $productTempMap, $sizeTempMap, $colorTempMap);
        }
    }

    /**
     * Par (product_id, size_id) resuelto de la línea → clave anti-deadlock (sin usar product_size.id).
     */
    private function bulkLineInventoryLockSortKey(
        array $line,
        array $productTempMap,
        array $sizeTempMap,
    ): string {
        $productId = $this->resolver->resolveProductId($line['productRef'] ?? [], $productTempMap);
        $sizeId = $this->resolver->resolveSizeId($line['sizeRef'] ?? [], $sizeTempMap);

        return $this->getInventoryLockSortKey($productId, $sizeId);
    }

    /**
     * Aplica al inventario el stock y precios de una línea de compra (mismo contrato que `lines[]` del bulk).
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $productTempMap
     * @param  array<string, int>  $sizeTempMap
     * @param  array<string, int>  $colorTempMap
     */
    public function applyStockForLine(
        array $line,
        int $warehouseId,
        int $tenantId,
        array $productTempMap = [],
        array $sizeTempMap = [],
        array $colorTempMap = [],
    ): void {
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
            $this->applyProductSizePurchase($productSize, $lineTotalQty, $line, $warehouseId, $tenantId, null);

            return;
        }

        $sumColorsQtyWithResolvedIds = $this->sumBulkLineQtyAttributedToResolvableColors($colors, $colorTempMap);
        if ($sumColorsQtyWithResolvedIds !== $lineTotalQty) {
            throw ValidationException::withMessages([
                'colors' => 'En compras con desglose por color, la suma de cantidades con color definido debe ser exactamente igual al total de la línea '
                    ."({$lineTotalQty}). Revise cada fila incluya colorId válido (o tempId resuelto) y que no falten variantes.",
            ]);
        }

        $productSize->loadMissing('product');

        $this->mergeProductSizePrices($productSize, $line);
        $productSize->save();

        $colorOps = [];
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
            $colorOps[] = ['id' => (int) $colorId, 'qty' => (int) $c['quantity']];
        }
        usort($colorOps, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        foreach ($colorOps as $op) {
            $this->recordPurchaseMovement($warehouseId, $tenantId, (int) $productSize->id, $op['id'], $op['qty']);
        }
    }

    /**
     * Bloqueo pesimista del maestro talla (alineado con ventas / reduce deadlocks).
     */
    protected function lockProductSizeMasterRow(int $productSizeId): void
    {
        $row = DB::table('product_size')->where('id', $productSizeId)->lockForUpdate()->first();
        if ($row === null) {
            throw ValidationException::withMessages([
                'stock' => 'No se encontró la talla del producto para aplicar stock.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function applyProductSizePurchase(
        ProductSize $productSize,
        int $delta,
        array $line,
        int $warehouseId,
        int $tenantId,
        ?int $colorId,
    ): void
    {
        $this->lockProductSizeMasterRow((int) $productSize->id);

        $this->mergeProductSizePrices($productSize, $line);
        $productSize->save();

        $this->recordPurchaseMovement($warehouseId, $tenantId, (int) $productSize->id, $colorId, $delta);
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

    protected function recordPurchaseMovement(
        int $warehouseId,
        int $tenantId,
        int $productSizeId,
        ?int $colorId,
        int $quantity,
    ): void
    {
        if ($quantity === 0) {
            return;
        }

        $this->inventoryMovementService->recordMovement(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: InventoryMovementDirection::In,
            quantity: $quantity,
            movementType: InventoryMovementType::Purchase,
            createdByUserId: Auth::id(),
        ));
    }
}

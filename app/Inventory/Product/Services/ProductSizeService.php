<?php

namespace App\Inventory\Product\Services;

use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\Product\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSizeService
{
    protected ProductHistoryService $historyService;

    public function __construct(
        ProductHistoryService $historyService,
        protected InventoryMovementService $inventoryMovementService,
    ) {
        $this->historyService = $historyService;
    }

    public function set(Product $product, int $sizeId, array $data, ?string $auditReason = null): void
    {
        DB::transaction(function () use ($product, $sizeId, $data, $auditReason): void {
            $row = DB::table('product_size')
                ->where('product_id', $product->id)
                ->where('size_id', $sizeId)
                ->lockForUpdate()
                ->first();

            $existingPivot = $row ? [
                'barcode' => $row->barcode,
                'purchase_price' => $row->purchase_price,
                'sale_price' => $row->sale_price,
                'min_sale_price' => $row->min_sale_price,
            ] : [];

            if (! $row) {
                DB::table('product_size')->insert([
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'barcode' => $data['barcode'] ?? null,
                    'purchase_price' => $data['purchasePrice'] ?? null,
                    'sale_price' => $data['salePrice'] ?? null,
                    'min_sale_price' => $data['minSalePrice'] ?? null,
                ]);
            } else {
                DB::table('product_size')->where('id', $row->id)->update([
                    'barcode' => $data['barcode'] ?? null,
                    'purchase_price' => $data['purchasePrice'] ?? null,
                    'sale_price' => $data['salePrice'] ?? null,
                    'min_sale_price' => $data['minSalePrice'] ?? null,
                ]);
            }

            $fresh = DB::table('product_size')
                ->where('product_id', $product->id)
                ->where('size_id', $sizeId)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                throw new RuntimeException('No se pudo persistir la talla del producto.');
            }

            if (array_key_exists('stock', $data)) {
                $hasColors = DB::table('product_size_color')
                    ->where('product_size_id', $fresh->id)
                    ->exists();

                if (! $hasColors) {
                    $this->reconcileInventory($product, (int) $fresh->id, null, (int) $data['stock']);
                }
            }

            $pivotData = [
                'barcode' => $fresh->barcode,
                'purchase_price' => $fresh->purchase_price,
                'sale_price' => $fresh->sale_price,
                'min_sale_price' => $fresh->min_sale_price,
            ];

            $eventType = $existingPivot === [] ? 'CREATED' : 'UPDATED';

            $this->historyService->logChange(
                $product,
                'SIZE',
                $sizeId,
                $eventType,
                $existingPivot,
                $pivotData,
                $auditReason,
            );
        });
    }

    public function currentStock(Product $product, int $productSizeId, ?int $colorId = null): int
    {
        return InventoryBalanceLookup::quantityFor((int) $product->warehouse_id, $productSizeId, $colorId);
    }

    public function remove(Product $product, int $sizeId): void
    {
        DB::transaction(function () use ($product, $sizeId): void {
            $row = DB::table('product_size')
                ->where('product_id', $product->id)
                ->where('size_id', $sizeId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return;
            }

            $oldData = [
                'barcode' => $row->barcode,
                'purchase_price' => $row->purchase_price,
                'sale_price' => $row->sale_price,
                'min_sale_price' => $row->min_sale_price,
            ];

            DB::table('product_size_color')
                ->where('product_size_id', $row->id)
                ->delete();

            DB::table('product_size')
                ->where('id', $row->id)
                ->delete();

            $this->historyService->logChange(
                $product,
                'SIZE',
                $sizeId,
                'DELETED',
                $oldData,
                null,
            );
        });
    }

    private function reconcileInventory(Product $product, int $productSizeId, ?int $colorId, int $quantity): void
    {
        $warehouseId = (int) $product->warehouse_id;
        if ($warehouseId < 1) {
            throw new RuntimeException('No se puede registrar inventario inicial sin almacén asociado al producto.');
        }

        if (! $product->relationLoaded('warehouse')) {
            $product->load('warehouse');
        }

        $tenantId = (int) $product->warehouse?->tenant_id;
        if ($tenantId < 1) {
            throw new RuntimeException('No se puede registrar inventario inicial sin tenant asociado al almacén.');
        }

        $this->inventoryMovementService->reconcileToPhysicalQuantity(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: InventoryMovementDirection::In,
            quantity: 1,
            movementType: InventoryMovementType::Reconciliation,
            createdByUserId: Auth::id(),
        ), max(0, $quantity));
    }
}

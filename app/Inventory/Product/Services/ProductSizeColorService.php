<?php

namespace App\Inventory\Product\Services;

use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\Product\Models\ProductSize;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSizeColorService
{
    protected ProductHistoryService $historyService;

    public function __construct(
        ProductHistoryService $historyService,
        protected InventoryMovementService $inventoryMovementService,
    ) {
        $this->historyService = $historyService;
    }

    public function set(ProductSize $productSize, int $colorId, array $data, bool $updateMaster = true, ?string $auditReason = null): void
    {
        DB::transaction(function () use ($productSize, $colorId, $data, $auditReason): void {
            $psId = (int) $productSize->id;

            $master = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new RuntimeException('No se encontró la talla del producto para actualizar el color.');
            }

            $pivotRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->lockForUpdate()
                ->first();

            $existingPivot = $pivotRow
                ? [
                    'product_size_id' => $pivotRow->product_size_id,
                    'color_id' => $pivotRow->color_id,
                ]
                : [];

            if (! $pivotRow) {
                DB::table('product_size_color')->insert([
                    'product_size_id' => $psId,
                    'color_id' => $colorId,
                ]);
            }

            if ($pivotRow !== null) {
                return;
            }

            if (! $productSize->relationLoaded('product')) {
                $productSize->load('product');
            }

            $this->recordInitialInventory($productSize, $colorId, (int) ($data['stock'] ?? 0));

            $eventType = $existingPivot === [] ? 'CREATED' : 'UPDATED';

            $newData = ['product_size_id' => $psId, 'color_id' => $colorId, 'size_id_ref' => $productSize->size_id];
            $oldData = $existingPivot !== []
                ? array_merge($existingPivot, ['size_id_ref' => $productSize->size_id])
                : [];

            $this->historyService->logChange(
                $productSize->product,
                'COLOR',
                $colorId,
                $eventType,
                $oldData,
                $newData,
                $auditReason,
            );
        });
    }

    public function currentStock(ProductSize $productSize, int $colorId): int
    {
        if (! $productSize->relationLoaded('product')) {
            $productSize->load('product');
        }

        return InventoryBalanceLookup::quantityFor((int) $productSize->product->warehouse_id, (int) $productSize->id, $colorId);
    }

    public function remove(ProductSize $productSize, int $colorId, ?string $auditReason = null): void
    {
        DB::transaction(function () use ($productSize, $colorId, $auditReason): void {
            $psId = (int) $productSize->id;

            $master = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new RuntimeException('No se encontró la talla del producto para eliminar el color.');
            }

            $pivotRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->lockForUpdate()
                ->first();

            if ($pivotRow === null) {
                $productSize->unsetRelation('productSizeColors');

                return;
            }

            $oldPivotSnapshot = [
                'product_size_id' => (int) $pivotRow->product_size_id,
                'color_id' => (int) $pivotRow->color_id,
            ];

            DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->delete();

            $productSize->unsetRelation('productSizeColors');

            if (! $productSize->relationLoaded('product')) {
                $productSize->load('product');
            }

            $oldForLog = array_merge($oldPivotSnapshot, ['size_id_ref' => $productSize->size_id]);

            $this->historyService->logChange(
                $productSize->product,
                'COLOR',
                $colorId,
                'DELETED',
                $oldForLog,
                null,
                $auditReason,
            );
        });
    }

    public function replacePivotColor(ProductSize $productSize, int $fromColorId, int $toColorId, ?string $auditReason = null): void
    {
        if ($fromColorId === $toColorId) {
            throw new RuntimeException('El color destino debe ser distinto al actual.');
        }

        DB::transaction(function () use ($productSize, $fromColorId, $toColorId, $auditReason): void {
            $psId = (int) $productSize->id;

            $master = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new RuntimeException('No se encontró la talla del producto para reemplazar color.');
            }

            $sortedColorIds = [$fromColorId, $toColorId];
            sort($sortedColorIds);
            foreach ($sortedColorIds as $cid) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $cid)
                    ->lockForUpdate()
                    ->first();
            }

            $fromRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $fromColorId)
                ->first();

            if ($fromRow === null) {
                throw new RuntimeException('Este color no está asignado a la talla seleccionada.');
            }

            $this->remove($productSize, $fromColorId, $auditReason);
            $this->set($productSize, $toColorId, [], true, $auditReason);
        });
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }

    private function recordInitialInventory(ProductSize $productSize, int $colorId, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        if (! $productSize->relationLoaded('product')) {
            $productSize->load('product');
        }

        $warehouseId = (int) $productSize->product->warehouse_id;
        if ($warehouseId < 1) {
            throw new RuntimeException('No se puede registrar inventario inicial sin almacén asociado al producto.');
        }

        if (! $productSize->product->relationLoaded('warehouse')) {
            $productSize->product->load('warehouse');
        }

        $tenantId = (int) $productSize->product->warehouse?->tenant_id;
        if ($tenantId < 1) {
            throw new RuntimeException('No se puede registrar inventario inicial sin tenant asociado al almacén.');
        }

        $this->inventoryMovementService->recordMovement(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: (int) $productSize->id,
            colorId: $colorId,
            direction: InventoryMovementDirection::In,
            quantity: $quantity,
            movementType: InventoryMovementType::InitialInventory,
            createdByUserId: Auth::id(),
        ));
    }
}

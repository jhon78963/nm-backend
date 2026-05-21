<?php

namespace App\Inventory\InventoryLedger\Services;

use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Support\StockAvailability;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryMovementService
{
    public function recordMovement(InventoryMovementDTO $dto): InventoryMovement
    {
        if ($dto->quantity < 1) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($dto): InventoryMovement {
            $warehouse = Warehouse::query()->findOrFail($dto->warehouseId);
            $warehouseId = (int) $warehouse->id;
            $tenantId = (int) $warehouse->tenant_id;

            if ($tenantId !== $dto->tenantId) {
                throw new InvalidArgumentException('El almacén no pertenece al tenant indicado.');
            }

            $productSize = ProductSize::query()->findOrFail($dto->productSizeId);
            $productId = (int) $productSize->product_id;

            $balance = $this->findOrCreateBalance(
                $tenantId,
                $warehouseId,
                $productId,
                $dto->productSizeId,
                $dto->colorId,
            );

            $current = (int) $balance->quantity;

            if ($dto->direction === InventoryMovementDirection::Out) {
                StockAvailability::assertCanDecrement($current, $dto->quantity);
                $newQuantity = $current - $dto->quantity;
            } else {
                $newQuantity = $current + $dto->quantity;
            }

            $balance->quantity = $newQuantity;
            $balance->save();

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_size_id' => $dto->productSizeId,
                'color_id' => $dto->colorId,
                'direction' => $dto->direction,
                'quantity' => $dto->quantity,
                'movement_type' => $dto->movementType,
                'reference_type' => $dto->referenceType,
                'reference_id' => $dto->referenceId,
                'balance_after_movement' => $newQuantity,
                'occurred_at' => $dto->occurredAt ?? now(),
                'created_by_user_id' => $dto->createdByUserId,
            ]);

            if ($dto->colorId !== null) {
                $this->syncMasterBalanceToColorSum(
                    $warehouseId,
                    $dto->productSizeId,
                    $dto->createdByUserId,
                );
            }

            return $movement;
        });
    }

    public function getAvailableQuantity(int $warehouseId, int $productSizeId, ?int $colorId): int
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $productSize = ProductSize::query()->findOrFail($productSizeId);

        return (int) ($this->balanceQuery(
            (int) $warehouse->tenant_id,
            $warehouseId,
            (int) $productSize->product_id,
            $productSizeId,
            $colorId,
        )->value('quantity') ?? 0);
    }

    public function reconcileToPhysicalQuantity(InventoryMovementDTO $dto, int $physicalQuantity): ?InventoryMovement
    {
        if ($physicalQuantity < 0) {
            throw new InvalidArgumentException('La cantidad física no puede ser negativa.');
        }

        return DB::transaction(function () use ($dto, $physicalQuantity): ?InventoryMovement {
            $warehouse = Warehouse::query()->findOrFail($dto->warehouseId);
            $warehouseId = (int) $warehouse->id;
            $tenantId = (int) $warehouse->tenant_id;

            if ($tenantId !== $dto->tenantId) {
                throw new InvalidArgumentException('El almacén no pertenece al tenant indicado.');
            }

            $productSize = ProductSize::query()->findOrFail($dto->productSizeId);
            $productId = (int) $productSize->product_id;

            $balance = $this->findOrCreateBalance(
                $tenantId,
                $warehouseId,
                $productId,
                $dto->productSizeId,
                $dto->colorId,
            );

            $current = (int) $balance->quantity;
            $diff = $physicalQuantity - $current;

            if ($diff === 0) {
                return null;
            }

            $direction = $diff > 0 ? InventoryMovementDirection::In : InventoryMovementDirection::Out;
            $quantity = abs($diff);

            if ($direction === InventoryMovementDirection::Out) {
                StockAvailability::assertCanDecrement($current, $quantity);
            }

            $balance->quantity = $physicalQuantity;
            $balance->save();

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_size_id' => $dto->productSizeId,
                'color_id' => $dto->colorId,
                'direction' => $direction,
                'quantity' => $quantity,
                'movement_type' => $dto->movementType,
                'reference_type' => $dto->referenceType,
                'reference_id' => $dto->referenceId,
                'balance_after_movement' => $physicalQuantity,
                'occurred_at' => $dto->occurredAt ?? now(),
                'created_by_user_id' => $dto->createdByUserId,
            ]);

            if ($dto->colorId !== null) {
                $this->syncMasterBalanceToColorSum(
                    $warehouseId,
                    $dto->productSizeId,
                    $dto->createdByUserId,
                );
            }

            return $movement;
        });
    }

    /**
     * Alinea el balance maestro (color_id = null) con la suma de variantes por color
     * cuando la talla tiene colores en `product_size_color`.
     */
    public function syncMasterBalanceToColorSum(
        int $warehouseId,
        int $productSizeId,
        ?int $createdByUserId = null,
    ): void {
        if ($warehouseId < 1 || $productSizeId < 1) {
            return;
        }

        if (! DB::table('product_size_color')->where('product_size_id', $productSizeId)->exists()) {
            return;
        }

        $productSize = ProductSize::query()->with('product.warehouse')->find($productSizeId);
        if ($productSize === null) {
            return;
        }

        $tenantId = (int) ($productSize->product->warehouse?->tenant_id ?? 0);
        if ($tenantId < 1) {
            return;
        }

        $colorIds = DB::table('product_size_color')
            ->where('product_size_id', $productSizeId)
            ->pluck('color_id');

        $total = 0;
        foreach ($colorIds as $colorId) {
            $total += $this->getAvailableQuantity($warehouseId, $productSizeId, (int) $colorId);
        }

        $this->reconcileToPhysicalQuantity(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: null,
            direction: InventoryMovementDirection::In,
            quantity: 1,
            movementType: InventoryMovementType::Reconciliation,
            createdByUserId: $createdByUserId ?? Auth::id(),
        ), max(0, $total));
    }

    /**
     * Pone en cero un balance de color que ya no está en `product_size_color`.
     */
    public function zeroColorBalance(int $warehouseId, int $productSizeId, int $colorId): void
    {
        if ($warehouseId < 1 || $productSizeId < 1 || $colorId < 1) {
            return;
        }

        $current = $this->getAvailableQuantity($warehouseId, $productSizeId, $colorId);
        if ($current === 0) {
            return;
        }

        $productSize = ProductSize::query()->with('product.warehouse')->find($productSizeId);
        if ($productSize === null) {
            return;
        }

        $tenantId = (int) ($productSize->product->warehouse?->tenant_id ?? 0);
        if ($tenantId < 1) {
            return;
        }

        $this->reconcileToPhysicalQuantity(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: InventoryMovementDirection::Out,
            quantity: 1,
            movementType: InventoryMovementType::Reconciliation,
            createdByUserId: Auth::id(),
        ), 0);
    }

    public function getTotalByProductSize(int $warehouseId, int $productSizeId): int
    {
        return (int) InventoryBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_size_id', $productSizeId)
            ->sum('quantity');
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function balanceQuery(int $tenantId, int $warehouseId, int $productId, int $productSizeId, ?int $colorId, bool $lockForUpdate = false): Builder
    {
        $q = InventoryBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('product_size_id', $productSizeId);

        if ($colorId === null) {
            $q->whereNull('color_id');
        } else {
            $q->where('color_id', $colorId);
        }

        if ($lockForUpdate) {
            $q->lockForUpdate();
        }

        return $q;
    }

    private function findOrCreateBalance(int $tenantId, int $warehouseId, int $productId, int $productSizeId, ?int $colorId): InventoryBalance
    {
        $balance = $this->balanceQuery($tenantId, $warehouseId, $productId, $productSizeId, $colorId, true)->first();

        if ($balance !== null) {
            return $balance;
        }

        InventoryBalance::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_size_id' => $productSizeId,
                'color_id' => $colorId,
            ],
            [
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'quantity' => 0,
            ],
        );

        return $this->balanceQuery($tenantId, $warehouseId, $productId, $productSizeId, $colorId, true)->firstOrFail();
    }
}

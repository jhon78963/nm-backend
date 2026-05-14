<?php

namespace App\Inventory\InventoryLedger\Services;

use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Support\StockAvailability;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
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

            $productSize = ProductSize::query()->lockForUpdate()->findOrFail($dto->productSizeId);

            $balance = $this->balanceQuery($warehouseId, $dto->productSizeId, $dto->colorId, true)->first();

            if ($balance === null) {
                InventoryBalance::query()->create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productSize->product_id,
                    'product_size_id' => $dto->productSizeId,
                    'color_id' => $dto->colorId,
                    'quantity' => 0,
                ]);

                $balance = $this->balanceQuery($warehouseId, $dto->productSizeId, $dto->colorId, true)->firstOrFail();
            }

            $current = (int) $balance->quantity;

            if ($dto->direction === InventoryMovementDirection::Out) {
                StockAvailability::assertCanDecrement($current, $dto->quantity);
                $newQuantity = $current - $dto->quantity;
            } else {
                $newQuantity = $current + $dto->quantity;
            }

            $balance->quantity = $newQuantity;
            $balance->save();

            return InventoryMovement::query()->create([
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
        });
    }

    public function getAvailableQuantity(int $warehouseId, int $productSizeId, ?int $colorId): int
    {
        return (int) ($this->balanceQuery($warehouseId, $productSizeId, $colorId)->value('quantity') ?? 0);
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
    private function balanceQuery(int $warehouseId, int $productSizeId, ?int $colorId, bool $lockForUpdate = false): Builder
    {
        $q = InventoryBalance::query()
            ->where('warehouse_id', $warehouseId)
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
}

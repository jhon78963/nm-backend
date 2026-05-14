<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Concerns\ProvidesInventoryLockSortKey;
use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use App\Inventory\Purchase\Models\PurchaseLine;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula una compra y revierte el stock aplicado en inventario.
 */
class PurchaseCancellationService
{
    use ProvidesInventoryLockSortKey;

    public function __construct(
        protected InventoryMovementService $inventoryMovementService,
    ) {
    }

    public function cancel(Purchase $purchase, ?string $reason = null): void
    {
        if ($purchase->is_deleted) {
            throw ValidationException::withMessages(['purchase' => 'La compra no existe o fue eliminada.']);
        }
        if ($purchase->status === PurchaseStatus::Cancelled) {
            throw ValidationException::withMessages(['purchase' => 'La compra ya está anulada.']);
        }

        DB::transaction(function () use ($purchase, $reason): void {
            $purchase->load(['lines.colorDeltas']);

            $lines = $purchase->lines;
            $psIds = $lines->pluck('product_size_id')->filter()->unique()->values()->all();
            $pairByProductSizeId = [];
            if ($psIds !== []) {
                foreach (
                    DB::table('product_size')
                        ->whereIn('id', $psIds)
                        ->get(['id', 'product_id', 'size_id']) as $row
                ) {
                    $pairByProductSizeId[(int) $row->id] = [(int) $row->product_id, (int) $row->size_id];
                }
            }

            $lines = $lines->sortBy(function (PurchaseLine $line) use ($pairByProductSizeId): string {
                $psId = (int) $line->product_size_id;
                if ($psId > 0 && isset($pairByProductSizeId[$psId])) {
                    [$pid, $sid] = $pairByProductSizeId[$psId];

                    return $this->getInventoryLockSortKey($pid, $sid);
                }

                return $this->getInventoryLockSortKey((int) $line->product_id, (int) $line->size_id);
            })->values();

            foreach ($lines as $line) {
                $this->revertLineStock($line, $purchase);
            }

            $purchase->status = PurchaseStatus::Cancelled;
            $purchase->cancelled_at = now();
            $purchase->cancellation_reason = $reason;
            $purchase->cancellation_user_id = Auth::id();
            $purchase->last_modifier_user_id = Auth::id();
            $purchase->last_modification_time = now();
            $purchase->save();
        });
    }

    /**
     * Revierte en inventario el efecto de una sola línea de compra (deltas de color + stock talla).
     */
    public function revertLineStock(PurchaseLine $line, ?Purchase $purchase = null): void
    {
        $line->loadMissing(['colorDeltas']);
        $purchase ??= $line->purchase()->firstOrFail();
        $warehouseId = (int) $purchase->warehouse_id;
        $tenantId = (int) Warehouse::query()->findOrFail($warehouseId)->tenant_id;
        $productSizeId = (int) $line->product_size_id;
        $deltaSize = (int) $line->size_stock_delta;

        if ($line->has_color_breakdown) {
            $sumColorDeltas = (int) $line->colorDeltas->sum(
                fn ($d) => (int) $d->quantity,
            );
            if ($sumColorDeltas !== $deltaSize) {
                throw new \Exception(
                    'Inconsistencia detectada: El total a revertir en la talla no coincide con la suma de los colores.',
                );
            }
        }

        if (! DB::table('product_size')->where('id', $productSizeId)->exists()) {
            throw ValidationException::withMessages([
                'stock' => 'No se encontró la talla del producto para revertir el stock.',
            ]);
        }

        if (! $line->has_color_breakdown) {
            $available = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $productSizeId, null);
            if ($available < $deltaSize) {
                throw ValidationException::withMessages([
                    'stock' => "No se puede anular: stock insuficiente en la talla (actual {$available}, revertir {$deltaSize}).",
                ]);
            }

            $this->recordPurchaseCancelMovement($warehouseId, $tenantId, $productSizeId, null, $deltaSize, (int) $line->purchase_id);

            return;
        }

        foreach ($line->colorDeltas->sortBy(fn ($d) => (int) $d->color_id) as $delta) {
            $colorId = (int) $delta->color_id;
            $qty = (int) $delta->quantity;
            $available = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $productSizeId, $colorId);
            if ($available < $qty) {
                throw ValidationException::withMessages([
                    'stock' => "No se puede anular: stock insuficiente en color ID {$colorId} para la talla (actual {$available}, revertir {$qty}).",
                ]);
            }

            $this->recordPurchaseCancelMovement($warehouseId, $tenantId, $productSizeId, $colorId, $qty, (int) $line->purchase_id);
        }
    }

    private function recordPurchaseCancelMovement(
        int $warehouseId,
        int $tenantId,
        int $productSizeId,
        ?int $colorId,
        int $quantity,
        int $purchaseId,
    ): void {
        if ($quantity === 0) {
            return;
        }

        $this->inventoryMovementService->recordMovement(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: InventoryMovementDirection::Out,
            quantity: $quantity,
            movementType: InventoryMovementType::PurchaseCancel,
            referenceType: Purchase::class,
            referenceId: $purchaseId,
            createdByUserId: Auth::id(),
        ));
    }
}

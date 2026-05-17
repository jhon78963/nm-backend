<?php

namespace App\Inventory\InventoryLedger\Resources;

use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
class InventoryKardexMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryMovement $movement */
        $movement = $this->resource;

        $quantity = (int) $movement->quantity;
        $balanceAfter = (int) $movement->balance_after_movement;
        $balanceBefore = $movement->direction === InventoryMovementDirection::In
            ? $balanceAfter - $quantity
            : $balanceAfter + $quantity;

        return [
            'id' => (int) $movement->id,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'date' => $movement->occurred_at->toDateString(),
            'time' => $movement->occurred_at->format('H:i:s'),
            'direction' => $movement->direction->value,
            'movement_type' => $movement->movement_type->value,
            'movement_type_label' => self::movementTypeLabel($movement->movement_type),
            'quantity' => $quantity,
            'balance_before_movement' => $balanceBefore,
            'balance_after_movement' => $balanceAfter,
            'reference' => self::referencePayload($movement),
            'created_by' => $movement->createdBy !== null
                ? [
                    'id' => (int) $movement->createdBy->id,
                    'name' => trim(implode(' ', array_filter([
                        $movement->createdBy->name,
                        $movement->createdBy->paternal_surname ?? $movement->createdBy->surname ?? null,
                    ]))),
                ]
                : null,
            'warehouse_id' => (int) $movement->warehouse_id,
            'product_size_id' => (int) $movement->product_size_id,
            'product_id' => $movement->productSize !== null ? (int) $movement->productSize->product_id : null,
            'color' => $movement->color !== null
                ? [
                    'id' => (int) $movement->color->id,
                    'description' => $movement->color->description,
                ]
                : null,
            'size' => $movement->productSize?->size !== null
                ? [
                    'id' => (int) $movement->productSize->size->id,
                    'description' => $movement->productSize->size->description,
                ]
                : null,
        ];
    }

    private static function movementTypeLabel(InventoryMovementType $type): string
    {
        return match ($type) {
            InventoryMovementType::InitialInventory => 'Inventario inicial',
            InventoryMovementType::Sale => 'Venta',
            InventoryMovementType::Purchase => 'Compra / orden',
            InventoryMovementType::PurchaseCancel => 'Anulación de compra',
            InventoryMovementType::ManualAdjustment => 'Ajuste manual',
            InventoryMovementType::Reconciliation => 'Reconciliación',
            InventoryMovementType::Transfer => 'Transferencia',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function referencePayload(InventoryMovement $movement): ?array
    {
        if ($movement->reference_type === null || $movement->reference_id === null) {
            return null;
        }

        $reference = $movement->relationLoaded('reference') ? $movement->reference : null;

        $payload = [
            'morph_class' => $movement->reference_type,
            'morph_short' => class_basename($movement->reference_type),
            'id' => (int) $movement->reference_id,
        ];

        if ($reference !== null && isset($reference->code)) {
            $payload['code'] = (string) $reference->code;
        }

        return $payload;
    }
}

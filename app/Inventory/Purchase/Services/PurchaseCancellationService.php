<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Color\Models\Color;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Anula una compra y revierte el stock aplicado en inventario.
 */
class PurchaseCancellationService
{
    public function __construct(
        protected ProductSizeColorService $productSizeColorService,
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

        $purchase->load(['lines.colorDeltas']);

        foreach ($purchase->lines as $line) {
            $productSize = ProductSize::query()->findOrFail($line->product_size_id);

            foreach ($line->colorDeltas as $delta) {
                $colorId = (int) $delta->color_id;
                Color::query()->where('is_deleted', false)->findOrFail($colorId);

                $existing = $productSize->productSizeColors()
                    ->wherePivot('color_id', $colorId)
                    ->first();

                $current = (int) ($existing?->pivot?->stock ?? 0);
                $qty = (int) $delta->quantity;
                if ($current < $qty) {
                    throw ValidationException::withMessages([
                        'stock' => "No se puede anular: stock insuficiente en color ID {$colorId} para la talla (actual {$current}, revertir {$qty}).",
                    ]);
                }
                $newStock = $current - $qty;
                $this->productSizeColorService->set($productSize, $colorId, ['stock' => $newStock]);
                $productSize->unsetRelation('productSizeColors');
            }

            $deltaSize = (int) $line->size_stock_delta;
            $productSize->refresh();
            if ((int) $productSize->stock < $deltaSize) {
                throw ValidationException::withMessages([
                    'stock' => 'No se puede anular: stock a nivel talla insuficiente para revertir.',
                ]);
            }
            $productSize->stock = (int) $productSize->stock - $deltaSize;
            $productSize->save();
        }

        $purchase->status = PurchaseStatus::Cancelled;
        $purchase->cancelled_at = now();
        $purchase->cancellation_reason = $reason;
        $purchase->cancellation_user_id = Auth::id();
        $purchase->last_modifier_user_id = Auth::id();
        $purchase->last_modification_time = now();
        $purchase->save();
    }
}

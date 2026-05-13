<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Color\Models\Color;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use App\Inventory\Purchase\Models\PurchaseLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($purchase, $reason): void {
            $purchase->load(['lines.colorDeltas']);

            foreach ($purchase->lines as $line) {
                $this->revertLineStock($line);
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
    public function revertLineStock(PurchaseLine $line): void
    {
        $line->loadMissing(['colorDeltas']);
        $productSizeId = (int) $line->product_size_id;

        $masterRow = DB::table('product_size')->where('id', $productSizeId)->lockForUpdate()->first();
        if ($masterRow === null) {
            throw ValidationException::withMessages([
                'stock' => 'No se encontró la talla del producto para revertir el stock.',
            ]);
        }

        $deltaSize = (int) $line->size_stock_delta;
        if ((int) $masterRow->stock < $deltaSize) {
            throw ValidationException::withMessages([
                'stock' => 'No se puede anular: stock a nivel talla insuficiente para revertir.',
            ]);
        }

        $productSize = ProductSize::query()->findOrFail($productSizeId);

        foreach ($line->colorDeltas->sortBy(fn ($d) => (int) $d->color_id) as $delta) {
            $colorId = (int) $delta->color_id;
            Color::query()->where('is_deleted', false)->findOrFail($colorId);

            $pivotRow = DB::table('product_size_color')
                ->where('product_size_id', $productSizeId)
                ->where('color_id', $colorId)
                ->lockForUpdate()
                ->first();

            $current = $pivotRow ? (int) $pivotRow->stock : 0;
            $qty = (int) $delta->quantity;
            if ($current < $qty) {
                throw ValidationException::withMessages([
                    'stock' => "No se puede anular: stock insuficiente en color ID {$colorId} para la talla (actual {$current}, revertir {$qty}).",
                ]);
            }
            $newStock = $current - $qty;
            $this->productSizeColorService->set($productSize, $colorId, ['stock' => $newStock], updateMaster: false);
            $productSize->unsetRelation('productSizeColors');
        }

        DB::table('product_size')->where('id', $productSizeId)->decrement('stock', $deltaSize);
    }
}

<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Purchase\Models\Purchase;
use App\Inventory\Purchase\Models\PurchaseLine;
use App\Inventory\Purchase\Models\PurchaseLineColorDelta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajuste de una línea de compra activa sin anular el documento completo.
 */
class PurchaseLineMutationService
{
    public function __construct(
        protected PurchaseCancellationService $purchaseCancellationService,
        protected PurchaseBulkService $purchaseBulkService,
    ) {
    }

    public function deleteLine(Purchase $purchase, PurchaseLine $line): void
    {
        $this->assertCanMutate($purchase, $line);

        DB::transaction(function () use ($purchase, $line): void {
            $this->purchaseCancellationService->revertLineStock($line);
            PurchaseLineColorDelta::query()
                ->where('purchase_line_id', $line->id)
                ->delete();
            $line->delete();
            $this->refreshPurchaseTotals($purchase);
            $this->touchPurchaseMeta($purchase);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validado por PurchaseLineUpdateRequest
     */
    public function updateLine(Purchase $purchase, PurchaseLine $line, array $data): void
    {
        $this->assertCanMutate($purchase, $line);

        DB::transaction(function () use ($purchase, $line, $data): void {
            $this->purchaseCancellationService->revertLineStock($line);

            PurchaseLineColorDelta::query()
                ->where('purchase_line_id', $line->id)
                ->delete();

            $hasBreakdown = (bool) $line->has_color_breakdown;
            $colorsPayload = [];

            if ($hasBreakdown) {
                $rows = $data['colorDeltas'] ?? [];
                if (! is_array($rows) || $rows === []) {
                    throw ValidationException::withMessages([
                        'colorDeltas' => 'Indica al menos una variante de color con cantidad.',
                    ]);
                }
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $colorsPayload[] = [
                        'colorId' => (int) ($row['colorId'] ?? 0),
                        'quantity' => max(0, (int) ($row['quantity'] ?? 0)),
                    ];
                }
            } else {
                $qty = max(1, (int) ($data['sizeOnlyQuantity'] ?? 1));
                $colorsPayload = [['quantity' => $qty]];
            }

            $lineTotalQty = 0;
            foreach ($colorsPayload as $c) {
                $lineTotalQty += (int) ($c['quantity'] ?? 0);
            }

            $purchasePrice = (float) ($data['purchasePrice'] ?? 0);
            $lineSubtotal = round($purchasePrice * max(0, $lineTotalQty), 2);

            $stockLine = [
                'productRef' => ['mode' => 'id', 'productId' => (int) $line->product_id],
                'sizeRef' => ['mode' => 'id', 'sizeId' => (int) $line->size_id],
                'productSizeId' => (int) $line->product_size_id,
                'barcode' => $data['barcode'] ?? null,
                'purchasePrice' => $purchasePrice,
                'salePrice' => isset($data['salePrice']) ? (float) $data['salePrice'] : null,
                'minSalePrice' => isset($data['minSalePrice']) ? (float) $data['minSalePrice'] : null,
                'colors' => $colorsPayload,
            ];

            $this->purchaseBulkService->applyStockForLine($stockLine, [], [], []);

            $line->barcode = $data['barcode'] ?? null;
            $line->purchase_price = $purchasePrice;
            $line->sale_price = $data['salePrice'] ?? null;
            $line->min_sale_price = $data['minSalePrice'] ?? null;
            $line->subtotal = $lineSubtotal;
            $line->size_stock_delta = max(0, $lineTotalQty);
            $line->save();

            if ($hasBreakdown) {
                foreach ($colorsPayload as $c) {
                    if (($c['quantity'] ?? 0) <= 0 || ($c['colorId'] ?? 0) < 1) {
                        continue;
                    }
                    PurchaseLineColorDelta::query()->create([
                        'purchase_line_id' => $line->id,
                        'color_id' => (int) $c['colorId'],
                        'quantity' => (int) $c['quantity'],
                    ]);
                }
            }

            $this->refreshPurchaseTotals($purchase);
            $this->touchPurchaseMeta($purchase);
        });
    }

    protected function assertCanMutate(Purchase $purchase, PurchaseLine $line): void
    {
        if ($purchase->is_deleted) {
            abort(404);
        }
        if ($purchase->isCancelled()) {
            throw ValidationException::withMessages(['purchase' => 'La compra está anulada.']);
        }
        if ((int) $line->purchase_id !== (int) $purchase->id) {
            abort(404);
        }
    }

    protected function refreshPurchaseTotals(Purchase $purchase): void
    {
        $sum = (float) PurchaseLine::query()
            ->where('purchase_id', $purchase->id)
            ->sum('subtotal');
        $purchase->total_subtotal = round($sum, 2);
        $purchase->save();
    }

    protected function touchPurchaseMeta(Purchase $purchase): void
    {
        $purchase->last_modifier_user_id = Auth::id();
        $purchase->last_modification_time = now();
        $purchase->save();
    }
}

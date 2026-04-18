<?php

namespace App\Inventory\Purchase\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use App\Inventory\Purchase\Models\PurchaseLine;
use App\Inventory\Purchase\Models\PurchaseLineColorDelta;
use App\Inventory\Purchase\Support\PurchasePayloadResolver;
use Illuminate\Support\Facades\Auth;
use JsonException;

/**
 * Persiste el documento de compra (cabecera + líneas + deltas de color) para trazabilidad y anulación.
 */
class PurchaseDocumentService
{
    public function __construct(
        protected PurchasePayloadResolver $resolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $productTempMap
     * @param  array<string, int>  $sizeTempMap
     * @param  array<string, int>  $colorTempMap
     */
    public function record(
        array $payload,
        int $warehouseId,
        ?int $vendorId,
        array $productTempMap,
        array $sizeTempMap,
        array $colorTempMap,
    ): Purchase {
        $purchaseBlock = $payload['purchase'] ?? [];
        $lines = $payload['lines'] ?? [];
        $totals = $payload['totals'] ?? [];
        $subtotal = (float) ($totals['grandSubtotal'] ?? 0);

        try {
            $payloadSnapshot = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $payloadSnapshot = null;
        }

        $registeredAt = $purchaseBlock['registeredAt'] ?? null;
        $registeredDate = $registeredAt ? date('Y-m-d', strtotime((string) $registeredAt)) : now()->format('Y-m-d');

        $purchase = Purchase::query()->create([
            'creator_user_id' => Auth::id(),
            'vendor_id' => $vendorId,
            'supplier_name' => (string) ($purchaseBlock['supplierName'] ?? ''),
            'document_note' => $purchaseBlock['documentNote'] ?? null,
            'registered_at' => $registeredDate,
            'warehouse_id' => $warehouseId,
            'currency' => (string) ($purchaseBlock['currency'] ?? 'PEN'),
            'status' => PurchaseStatus::Active,
            'total_subtotal' => $subtotal,
            'payload_json' => $payloadSnapshot,
            'is_deleted' => false,
        ]);

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $productId = $this->resolver->resolveProductId($line['productRef'] ?? [], $productTempMap);
            $sizeId = $this->resolver->resolveSizeId($line['sizeRef'] ?? [], $sizeTempMap);
            $product = Product::query()->where('is_deleted', false)->findOrFail($productId);
            $productSize = $this->resolver->resolveProductSize($product, $sizeId, $line);
            $productSize->refresh();

            $colors = array_map(
                fn (mixed $c): array => $this->resolver->normalizeLineColorRow(is_array($c) ? $c : []),
                $line['colors'] ?? [],
            );
            $hasColorKeys = $this->resolver->colorsHaveIds($colors);
            $lineTotalQty = 0;
            foreach ($colors as $c) {
                $lineTotalQty += $c['quantity'];
            }

            $isSizeOnly = ! $hasColorKeys && count($colors) === 1;

            $purchaseLine = PurchaseLine::query()->create([
                'purchase_id' => $purchase->id,
                'line_id' => isset($line['lineId']) ? (string) $line['lineId'] : null,
                'product_id' => $productId,
                'size_id' => $sizeId,
                'product_size_id' => $productSize->id,
                'barcode' => $line['barcode'] ?? null,
                'purchase_price' => $line['purchasePrice'] ?? null,
                'sale_price' => $line['salePrice'] ?? null,
                'min_sale_price' => $line['minSalePrice'] ?? null,
                'subtotal' => (float) ($line['subtotal'] ?? 0),
                'size_stock_delta' => max(0, $lineTotalQty),
                'has_color_breakdown' => ! $isSizeOnly,
            ]);

            if ($isSizeOnly) {
                continue;
            }

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
                PurchaseLineColorDelta::query()->create([
                    'purchase_line_id' => $purchaseLine->id,
                    'color_id' => (int) $colorId,
                    'quantity' => $c['quantity'],
                ]);
            }
        }

        return $purchase->fresh(['lines.colorDeltas']);
    }
}

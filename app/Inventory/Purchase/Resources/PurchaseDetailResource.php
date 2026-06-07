<?php

namespace App\Inventory\Purchase\Resources;

use App\Finance\CashMovement\Models\CashMovement;
use App\Inventory\Purchase\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Purchase */
class PurchaseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = null;
        if ($this->payload_json) {
            try {
                $payload = json_decode((string) $this->payload_json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $payload = null;
            }
        }

        return [
            'id' => $this->id,
            'supplierName' => $this->supplier_name,
            'vendorId' => $this->vendor_id,
            'documentNote' => $this->document_note,
            'registeredAt' => $this->registered_at?->format('Y-m-d'),
            'warehouseId' => $this->warehouse_id,
            'warehouseName' => $this->whenLoaded('warehouse', fn () => $this->warehouse->name),
            'currency' => $this->currency,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'totalSubtotal' => $this->effectivePurchaseTotal(),
            'creationTime' => $this->creation_time?->format('Y-m-d H:i:s'),
            'cancelledAt' => $this->cancelled_at?->format('Y-m-d H:i:s'),
            'cancellationReason' => $this->cancellation_reason,
            'lines' => PurchaseLineResource::collection($this->whenLoaded('lines')),
            'payloadSnapshot' => $payload,
            'linkedPayment' => $this->resolveLinkedPayment(),
        ];
    }

    /**
     * Pago/voucher vinculado desde un movimiento de caja (ej. gasto administrativo migrado).
     *
     * @return array<string, mixed>|null
     */
    private function resolveLinkedPayment(): ?array
    {
        if (! $this->relationLoaded('cashMovements')) {
            return null;
        }

        $movement = $this->cashMovements
            ->first(fn ($m) => $m->category === CashMovement::CATEGORY_INVENTORY_PURCHASE);

        if ($movement === null) {
            $movement = $this->cashMovements->first();
        }

        if ($movement === null) {
            return null;
        }

        $voucherPaths = $this->resolveVoucherPaths($movement);

        return [
            'cashMovementId' => $movement->id,
            'amount' => (float) $movement->amount,
            'paymentMethod' => $movement->payment_method,
            'description' => $movement->description,
            'date' => $movement->date?->format('Y-m-d H:i:s'),
            'voucherPath' => $voucherPaths[0] ?? $movement->voucher_path,
            'voucherPaths' => $voucherPaths,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveVoucherPaths(CashMovement $movement): array
    {
        if ($movement->relationLoaded('vouchers') && $movement->vouchers->isNotEmpty()) {
            return $movement->vouchers
                ->sortBy('sort_order')
                ->pluck('voucher_path')
                ->filter(static fn ($path) => is_string($path) && $path !== '')
                ->values()
                ->all();
        }

        if ($movement->voucher_path !== null && $movement->voucher_path !== '') {
            return [(string) $movement->voucher_path];
        }

        return [];
    }

    private function effectivePurchaseTotal(): float
    {
        $stored = (float) $this->total_subtotal;
        if ($stored > 0.00001) {
            return round($stored, 2);
        }
        if (! $this->relationLoaded('lines') || $this->lines->isEmpty()) {
            return round($stored, 2);
        }
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $ls = (float) $line->subtotal;
            if ($ls > 0.00001) {
                $sum += $ls;

                continue;
            }
            $pp = (float) ($line->purchase_price ?? 0);
            if ($line->has_color_breakdown) {
                $line->loadMissing('colorDeltas');
                $cq = 0;
                foreach ($line->colorDeltas as $d) {
                    $cq += (int) $d->quantity;
                }
                $sum += $pp * $cq;
            } else {
                $sum += $pp * (int) $line->size_stock_delta;
            }
        }

        return round($sum, 2);
    }
}

<?php

namespace App\Inventory\Purchase\Resources;

use App\Inventory\Purchase\Models\PurchaseLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseLine */
class PurchaseLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stored = (float) $this->subtotal;
        $effective = $stored > 0.00001
            ? $stored
            : $this->computeFallbackSubtotal();

        return [
            'id' => $this->id,
            'lineId' => $this->line_id,
            'productId' => $this->product_id,
            'productName' => $this->whenLoaded('product', fn () => $this->product->name),
            'sizeId' => $this->size_id,
            'sizeDescription' => $this->whenLoaded('size', fn () => $this->size->description),
            'productSizeId' => $this->product_size_id,
            'barcode' => $this->barcode,
            'purchasePrice' => $this->purchase_price,
            'salePrice' => $this->sale_price,
            'minSalePrice' => $this->min_sale_price,
            'subtotal' => round($effective, 2),
            'sizeStockDelta' => (int) $this->size_stock_delta,
            'hasColorBreakdown' => (bool) $this->has_color_breakdown,
            'colorDeltas' => PurchaseLineColorDeltaResource::collection($this->whenLoaded('colorDeltas')),
        ];
    }

    private function computeFallbackSubtotal(): float
    {
        $pp = (float) ($this->purchase_price ?? 0);
        if ($this->has_color_breakdown && $this->relationLoaded('colorDeltas')) {
            $sum = 0;
            foreach ($this->colorDeltas as $d) {
                $sum += (int) $d->quantity;
            }

            return $pp * $sum;
        }

        return $pp * (int) $this->size_stock_delta;
    }
}

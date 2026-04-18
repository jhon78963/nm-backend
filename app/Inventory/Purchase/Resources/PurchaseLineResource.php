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
            'subtotal' => (float) $this->subtotal,
            'sizeStockDelta' => (int) $this->size_stock_delta,
            'hasColorBreakdown' => (bool) $this->has_color_breakdown,
            'colorDeltas' => PurchaseLineColorDeltaResource::collection($this->whenLoaded('colorDeltas')),
        ];
    }
}

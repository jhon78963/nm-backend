<?php

namespace App\Inventory\Color\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tallas ligadas a un producto (`GET /colors/sizes`).
 * Expone pivote `product_size`: barcode, precios y stock para autocompletar compras.
 */
class SizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'productSizeId' => isset($this->productSizeId) ? (int) $this->productSizeId : null,
            'description' => $this->description,
            'stock' => isset($this->stock) ? (int) $this->stock : null,
            'barcode' => $this->barcode !== null && $this->barcode !== '' ? (string) $this->barcode : null,
            'purchasePrice' => $this->purchase_price !== null ? (float) $this->purchase_price : null,
            'salePrice' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'minSalePrice' => $this->min_sale_price !== null ? (float) $this->min_sale_price : null,
        ];
    }
}

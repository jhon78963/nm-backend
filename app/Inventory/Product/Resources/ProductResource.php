<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\Product\Support\PurchasePriceVisibility;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stock = (int) ($this->inventory_sum_qty ?? 0);
        /** Referencia desde la primera fila product–talla (las precios viven en `product_size`). */
        $primaryPs = ($this->relationLoaded('productSizes') && $this->productSizes->isNotEmpty())
            ? $this->productSizes->sortBy('id')->first()
            : null;

        /** @var ProductMediaUrlResolver $mediaUrlResolver */
        $mediaUrlResolver = app(ProductMediaUrlResolver::class);
        $gallery = $mediaUrlResolver->galleryUrlsForProduct($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name ?? '',
            'barcode' => $this->barcode,
            'stock' => $stock,
            'purchasePrice' => $this->when(
                PurchasePriceVisibility::canView($request),
                fn () => $primaryPs !== null ? (float) ($primaryPs->purchase_price ?? 0) : 0,
            ),
            'salePrice' => $primaryPs !== null ? (float) ($primaryPs->sale_price ?? 0) : 0,
            'minSalePrice' => $primaryPs !== null ? (float) ($primaryPs->min_sale_price ?? 0) : 0,
            'cashDiscount' => $this->cash_discount,
            'percentageDiscount' => $this->percentage_discount,
            'description' => $this->description,
            'status' => $this->status,
            'genderId' => $this->gender_id,
            'gender' => $this->gender?->name ?? 'Sin género',
            'warehouseId' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->name ?? 'Sin almacén',
            'filter' => !$this->sizes()->exists(),
            'sizeTypeId' => $this->sizes->pluck('size_type_id')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'thumbnail' => $gallery[0] ?? null,
            'gallery' => $gallery,
            'media' => $this->mediaItems($mediaUrlResolver),
        ];
    }

    /**
     * @return list<array{id: int, filePath: string, publicUrl: string|null, fileName: string|null}>
     */
    private function mediaItems(ProductMediaUrlResolver $mediaUrlResolver): array
    {
        if (! $this->relationLoaded('media')) {
            $this->resource->load('media');
        }

        return $this->media
            ->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'filePath' => (string) $item->file_path,
                'publicUrl' => $mediaUrlResolver->absoluteUrl((string) $item->file_path),
                'fileName' => $item->file_name,
            ])
            ->values()
            ->all();
    }
}

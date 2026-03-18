<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;

class ProductSizeService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    public function set(Product $product, int $sizeId, array $data): void
    {
        $existingPivot = $product->sizes()
            ->where('size_id', $sizeId)
            ->first()
            ?->pivot
                ?->toArray() ?? [];

        $pivotData = [
            'barcode' => $data['barcode'],
            'stock' => $data['stock'],
            'purchase_price' => $data['purchasePrice'],
            'sale_price' => $data['salePrice'],
            'min_sale_price' => $data['minSalePrice'],
        ];

        $product->sizes()->syncWithoutDetaching([
            $sizeId => $pivotData
        ]);

        $eventType = empty($existingPivot) ? 'CREATED' : 'UPDATED';

        $this->historyService->logChange(
            $product,
            'SIZE',
            $sizeId,
            $eventType,
            $existingPivot,
            $pivotData
        );
    }

    public function remove(Product $product, int $sizeId): void
    {
        $oldData = $product->sizes()
            ->where('size_id', $sizeId)
            ->first()
            ?->pivot
                ?->toArray();

        $product->sizes()->detach($sizeId);

        if ($oldData) {
            $this->historyService->logChange(
                $product,
                'SIZE',
                $sizeId,
                'DELETED',
                $oldData,
                null
            );
        }
    }

    public function setStock(Product $product, int $sizeId, int $qty, array $data): void
    {
        $existingPivot = $product->sizes()
            ->where('size_id', $sizeId)
            ->first()
            ?->pivot
                ?->toArray();

        $currentStock = $existingPivot['stock'] ?? 0;
        $newStock = $currentStock + $qty;

        $pivotData = [
                'barcode' => $data['barcode'],
                'stock' => $newStock,
                'purchase_price' => $data['purchase_price'],
                'sale_price' => $data['sale_price'],
                'min_sale_price' => $data['min_sale_price'],
            ];

        $product->sizes()->syncWithoutDetaching([
            $sizeId => $pivotData
        ]);

        $eventType = empty($existingPivot) ? 'CREATED' : 'UPDATED';

        $this->historyService->logChange(
            $product,
            'SIZE',
            $sizeId,
            $eventType,
            $existingPivot ?? [],
            $pivotData
        );
    }
}

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
        // 1. Obtener datos anteriores si existían (para comparar cambios de precio/stock)
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

        // 2. Realizar la acción
        $product->sizes()->syncWithoutDetaching([
            $sizeId => $pivotData
        ]);

        // 3. Registrar Historial
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
        // Obtener datos antes de borrar para el log
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
}

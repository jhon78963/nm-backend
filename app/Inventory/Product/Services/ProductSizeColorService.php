<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\ProductSize;

class ProductSizeColorService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

 public function set(ProductSize $productSize, int $colorId, array $data): void
    {
        // 1. Datos anteriores
        $existingPivot = $productSize->productSizeColors()
            ->where('color_id', $colorId)
            ->first()
            ?->pivot
            ?->toArray() ?? [];

        // 2. Acción
        $productSize->productSizeColors()->syncWithoutDetaching([
            $colorId => ['stock' => $data['stock']]
        ]);

        // 3. Historial (Necesitamos el Producto Padre para vincularlo)
        if (!$productSize->relationLoaded('product')) {
            $productSize->load('product'); // Carga perezosa si no está
        }

        $eventType = empty($existingPivot) ? 'CREATED' : 'UPDATED';

        // Agregamos el ID de la talla al log para contexto
        $newData = ['stock' => $data['stock'], 'size_id_ref' => $productSize->size_id];
        $oldData = $existingPivot ? array_merge($existingPivot, ['size_id_ref' => $productSize->size_id]) : [];

        $this->historyService->logChange(
            $productSize->product,
            'COLOR',
            $colorId,
            $eventType,
            $oldData,
            $newData
        );
    }

    public function remove(ProductSize $productSize, int $colorId): void
    {
        $oldData = $productSize->productSizeColors()
            ->where('color_id', $colorId)
            ->first()
            ?->pivot
            ?->toArray();

        $productSize->productSizeColors()->detach($colorId);

        if ($oldData) {
            if (!$productSize->relationLoaded('product')) {
                $productSize->load('product');
            }

            $this->historyService->logChange(
                $productSize->product,
                'COLOR',
                $colorId,
                'DELETED',
                $oldData,
                null
            );
        }
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }
}

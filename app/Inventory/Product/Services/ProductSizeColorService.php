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
        $existingPivot = $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->first()
            ?->pivot
                ?->toArray() ?? [];

        $productSize->productSizeColors()->syncWithoutDetaching([
            $colorId => ['stock' => $data['stock']]
        ]);

        if (!$productSize->relationLoaded('product')) {
            $productSize->load('product');
        }

        $eventType = empty($existingPivot) ? 'CREATED' : 'UPDATED';
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
            ->wherePivot('color_id', $colorId)
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

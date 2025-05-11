<?php

namespace App\Product\Services;

use App\Product\Models\ProductSize;
use App\Shared\Services\ModelService;

class ProductSizeColorService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function add(ProductSize $productSize, int $colorId, array $color): void
    {
        $this->modelService->attach(
            $productSize,
            'productSizeColors',
            $colorId,
            [
                'stock' => $color['stock'],
            ]
        );
    }

    public function modify(ProductSize $productSize, int $colorId, array $color): void
    {
        $this->modelService->attach(
            $productSize,
            'productSizeColors',
            $colorId,
            [
                'stock' => $color['stock'],
            ]
        );
    }

    public function remove(ProductSize $productSize, int $colorId): void
    {
        $this->modelService->detach(
            $productSize,
            'productSizeColors',
            $colorId,
        );
    }
}

<?php

namespace App\Product\Services;

use App\Product\Models\Product;
use App\Shared\Services\ModelService;

class ProductColorService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function add(Product $product, int $colorId, array $color): void
    {
        $this->modelService->attach(
            $product,
            'colors',
            $colorId,
            [
                'stock' => $color['stock'],
                'price' => $color['price'],
            ]
        );
    }

    public function modify(Product $product, int $colorId, array $color): void
    {
        $this->modelService->attach(
            $product,
            'colors',
            $colorId,
            [
                'stock' => $color['stock'],
                'price' => $color['price'],
            ]
        );
    }

    public function remove(Product $product, int $colorId): void
    {
        $this->modelService->detach(
            $product,
            'colors',
            $colorId,
        );
    }
}

<?php

namespace App\Product\Services;

use App\Product\Models\Product;
use App\Shared\Services\ModelService;

class ProductImageService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function add(Product $product, int $imageId): void
    {
        $this->modelService->attach(
            $product,
            'images',
            $imageId,
            []
        );
    }


    public function remove(Product $product, int $imageId): void
    {
        $this->modelService->detach(
            $product,
            'images',
            $imageId,
        );
    }
}

<?php

namespace App\Product\Services;

use App\Product\Models\Product;
use App\Shared\Services\ModelService;
use DB;

class ProductImageService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }


    public function add(Product $product, string $path, string $size, string $name): void
    {
        $this->modelService->addImage(
            $product,
            'images',
            $path,
            ['size' => $size, 'name' => $name]
        );
    }

    public function getAll(Product $product)
    {
        return DB::table('product_image')
            ->where('product_id', '=', $product->id)
            ->get();
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

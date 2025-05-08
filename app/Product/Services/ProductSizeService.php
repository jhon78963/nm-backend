<?php

namespace App\Product\Services;

use App\Product\Models\Product;
use App\Shared\Services\ModelService;

class ProductSizeService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function add(Product $product, int $sizeId, array $size): void
    {
        $this->modelService->attach(
            $product,
            'sizes',
            $sizeId,
            [
                'barcode' => $size['barcode'],
                'stock' => $size['stock'],
                'purchase_price' => $size['purchasePrice'],
                'sale_price' => $size['salePrice'],
                'min_sale_price' => $size['minSalePrice'],
            ]
        );
    }

    public function modify(Product $product, int $sizeId, array $size): void
    {
        $this->modelService->attach(
            $product,
            'sizes',
            $sizeId,
            [
                'barcode' => $size['barcode'],
                'stock' => $size['stock'],
                'purchase_price' => $size['purchasePrice'],
                'sale_price' => $size['salePrice'],
                'min_sale_price' => $size['minSalePrice'],
            ]
        );
    }

    public function remove(Product $product, int $sizeId): void
    {
        $this->modelService->detach(
            $product,
            'sizes',
            $sizeId,
        );
    }
}

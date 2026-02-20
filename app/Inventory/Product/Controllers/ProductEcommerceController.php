<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Resources\ProductEcommerceResource;
use App\Shared\Foundation\Controllers\Controller;

class ProductEcommerceController extends Controller
{
    public function show($id)
    {
        $product = Product::with([
            'imagesEcommerce',
            'productSizes.size',
            'productSizes.colors'
        ])->findOrFail($id);

        return new ProductEcommerceResource($product);
    }

    public function index()
    {
        $products = Product::with([
            'imagesEcommerce',
            'productSizes.size',
            'productSizes.colors'
        ])->paginate(10);

        return ProductEcommerceResource::collection($products);
    }
}

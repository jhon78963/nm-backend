<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Resources\ProductEcommerceResource;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pública de catálogo para ecommerce (listado y detalle).
 */
class ProductEcommerceController extends Controller
{
    private const INDEX_LIMIT = 200;

    /**
     * @return array<int, string>
     */
    private function ecommerceWith(): array
    {
        return [
            'productSizes.size',
            'productSizes.colors',
            'imagesEcommerce',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(
            max((int) $request->query('limit', self::INDEX_LIMIT), 1),
            self::INDEX_LIMIT,
        );

        $products = Product::query()
            ->where('is_deleted', false)
            ->with($this->ecommerceWith())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(ProductEcommerceResource::collection($products));
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::query()
            ->where('is_deleted', false)
            ->whereKey($id)
            ->with($this->ecommerceWith())
            ->firstOrFail();

        return response()->json(new ProductEcommerceResource($product));
    }
}

<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Resources\ProductEcommerceResource;
use App\Inventory\Product\Support\EcommerceCatalogScope;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pública de catálogo para ecommerce (listado y detalle).
 */
class ProductEcommerceController extends Controller
{
    use EcommerceCatalogScope;

    private const INDEX_LIMIT = 200;

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->ecommerceWarehouseId();
        $limit = min(
            max((int) $request->query('limit', self::INDEX_LIMIT), 1),
            self::INDEX_LIMIT,
        );

        $products = $this->ecommerceProductQuery()
            ->with($this->ecommerceWith($warehouseId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(ProductEcommerceResource::collection($products));
    }

    public function show(int $id): JsonResponse
    {
        $warehouseId = $this->ecommerceWarehouseId();

        $product = $this->ecommerceProductQuery()
            ->whereKey($id)
            ->with($this->ecommerceWith($warehouseId))
            ->firstOrFail();

        return response()->json(new ProductEcommerceResource($product));
    }
}

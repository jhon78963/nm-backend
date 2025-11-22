<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\ProductAddRequest;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductSizeController extends Controller
{
    public function __construct(
        protected ProductSizeService $productSizeService
    ) {
    }

    public function add(
        ProductAddRequest $request,
        Product $product,
        int $sizeId
    ): JsonResponse {
        return DB::transaction(
            function () use ($request, $product, $sizeId): JsonResponse {
                $this->productSizeService->set(
                    $product,
                    $sizeId,
                    $request->validated()
                );

                return response()->json(
                    ['message' => 'Size added successfully.'],
                    201,
                );
            }
        );
    }

    public function modify(
        ProductAddRequest $request,
        Product $product,
        int $sizeId
    ): JsonResponse {
        return DB::transaction(
            function () use ($request, $product, $sizeId): JsonResponse {
                $this->productSizeService->set(
                    $product,
                    $sizeId,
                    $request->validated()
                );

                return response()->json(
                    ['message' => 'Size modified successfully.'],
                    200,
                );
            }
        );
    }

    public function remove(
        Product $product,
        int $sizeId
    ): JsonResponse {
        return DB::transaction(
            function () use ($product, $sizeId): JsonResponse {
                $this->productSizeService->remove(
                    $product,
                    $sizeId
                );

                return response()->json(
                    ['message' => 'Size removed successfully.'],
                    200,
                );
            }
        );
    }

    public function get(int $productId, int $sizeId): JsonResponse
    {
        $productSize = ProductSize::where('product_id', $productId)
            ->where('size_id', $sizeId)
            ->first();

        return response()->json([
            'productSizeId' => $productSize?->id
        ]);
    }
}

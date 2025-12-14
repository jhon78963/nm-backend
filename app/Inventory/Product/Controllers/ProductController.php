<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Requests\ProductCreateRequest;
use App\Inventory\Product\Requests\ProductUpdateRequest;
use App\Inventory\Product\Resources\ProductResource;
use App\Inventory\Product\Services\ProductService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected SharedService $sharedService,
    ) {}

    public function create(ProductCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $data['warehouse_id'] = 1;
            $product = $this->productService->create($data);

            return response()->json([
                'message'   => 'Product created successfully.',
                'productId' => $product->id,
            ], 201);
        });
    }

    public function update(ProductUpdateRequest $request, Product $product): JsonResponse
    {
        return DB::transaction(function () use ($request, $product) {
            $this->productService->validate($product, 'Product');
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->productService->update($product, $data);

            return response()->json([
                'message'   => 'Product updated successfully.',
                'productId' => $product->id,
            ]);
        });
    }

    public function delete(Product $product): JsonResponse
    {
        return DB::transaction(function () use ($product) {
            $this->productService->validate($product, 'Product');
            $this->productService->delete($product);

            return response()->json(['message' => 'Product deleted.']);
        });
    }

    public function get(Product $product): JsonResponse
    {
        $this->productService->validate($product, 'Product');
        return response()->json(new ProductResource($product));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $filters = array_filter([
            'gender_id' => $request->input('genderId'),
        ]);

        $stockSubquery = '(SELECT COALESCE(SUM(stock), 0) FROM product_size WHERE product_size.product_id = products.id)';

        $queryResult = $this->sharedService->query(
            request:      $request,
            entityName:   'Inventory\\Product',
            modelName:    'Product',
            columnSearch: ['id', 'name', 'gender.name', $stockSubquery],
            filters:      $filters,
            extendQuery:  fn($q) => $q->withSum('sizes as sizes_sum_stock', 'product_size.stock'),
        );

        return response()->json(new GetAllCollection(
            ProductResource::collection($queryResult['collection']),
            $queryResult['total'],
            $queryResult['pages'],
        ));
    }
}

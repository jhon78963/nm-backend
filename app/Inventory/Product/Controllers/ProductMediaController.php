<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Requests\ProductMediaStoreRequest;
use App\Inventory\Product\Services\ProductMediaService;
use App\Inventory\Product\Services\ProductService;
use App\Models\Media;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductMediaController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductMediaService $productMediaService,
    ) {}

    public function store(ProductMediaStoreRequest $request, Product $product): JsonResponse
    {
        return DB::transaction(function () use ($request, $product): JsonResponse {
            $this->productService->validate($product, 'Product');

            $result = $this->productMediaService->uploadAndSync(
                $product,
                $request->file('image'),
            );

            return response()->json(
                $this->storeResponsePayload($product, $result),
                $this->syncHttpStatus($result['wooCommerceSync'], 201),
            );
        });
    }

    public function destroy(Product $product, Media $media): JsonResponse
    {
        return DB::transaction(function () use ($product, $media): JsonResponse {
            $this->productService->validate($product, 'Product');

            $result = $this->productMediaService->deleteAndSync($product, $media);

            return response()->json([
                'message' => 'Imagen eliminada correctamente.',
                'productId' => $product->id,
                'deletedMediaId' => $result['deletedMediaId'],
                'wooCommerceSync' => $result['wooCommerceSync'],
            ], $this->syncHttpStatus($result['wooCommerceSync'], 200));
        });
    }

    /**
     * @param  array{
     *     media: array{id: int, filePath: string, publicUrl: string|null, fileName: string|null},
     *     wooCommerceSync: array{attempted: bool, products: int, variations: int, errors: int, error: string|null}
     * }  $result
     * @return array<string, mixed>
     */
    private function storeResponsePayload(Product $product, array $result): array
    {
        return [
            'message' => 'Imagen subida correctamente.',
            'productId' => $product->id,
            'media' => $result['media'],
            'wooCommerceSync' => $result['wooCommerceSync'],
        ];
    }

    /**
     * @param  array{attempted: bool, errors: int}  $sync
     */
    private function syncHttpStatus(array $sync, int $successStatus): int
    {
        return $sync['attempted'] && $sync['errors'] > 0 ? 207 : $successStatus;
    }
}

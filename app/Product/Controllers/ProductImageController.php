<?php

namespace App\Product\Controllers;

use App\Image\Resources\ImageResource;
use App\Product\Models\Product;
use App\Product\Services\ProductImageService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\FileUploadRequest;
use App\Shared\Services\FileService;
use Illuminate\Http\JsonResponse;
use DB;

class ProductImageController extends Controller
{
    protected FileService $fileService;
    protected ProductImageService $productImageService;

    public function __construct(
        ProductImageService $productImageService,
        FileService $fileService,
    ) {
        $this->fileService = $fileService;
        $this->productImageService = $productImageService;
    }

    public function addImages(Product $product, FileUploadRequest $request)
    {
        DB::beginTransaction();
        try {
            $uploadedImages = $this->fileService ->upload($request);
            foreach($uploadedImages as $image)
            {
                $this->productImageService->add(
                    $product,
                    $image->path,
                );
            }
            DB::commit();
            return response()->json(['message' => 'Images uploaded.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json($e->getMessage());
        }
    }

    public function add(
        Product $product,
        int $imageId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->add(
                $product,
                $imageId,
            );
            DB::commit();
            return response()->json(['message' => 'Image added.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function getAll(Product $product)
    {
        $images = $this->productImageService->getAll($product);
        return response()->json( ImageResource::collection($images));
    }

    public function remove(
        Product $product,
        int $imageId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->remove(
                $product,
                $imageId,
            );
            DB::commit();
            return response()->json(['message' => 'Image removed.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}

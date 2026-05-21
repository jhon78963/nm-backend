<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Shared\Foundation\Requests\FileMultipleUploadRequest;
use App\Shared\Foundation\Services\FileService;
use App\Shared\Foundation\Services\NodeUploaderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ProductImageService
{
    public function __construct(
        protected FileService $fileService,
        protected NodeUploaderService $nodeUploaderService,
    ) {
    }

    public function add(Product $product, UploadedFile $file, string $size, string $name): void
    {
        $path = $this->nodeUploaderService->upload($file);

        $this->fileService->attach(
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

    public function remove(Product $product, string $path): void
    {
        $this->fileService->detach(
            $product,
            'images',
            $path
        );
    }

    public function removeAll(Product $product, FileMultipleUploadRequest $request): void
    {
        $paths = $request->input('path', []);
        foreach ($paths as $path) {
            $this->remove($product, $path);
        }
    }
}

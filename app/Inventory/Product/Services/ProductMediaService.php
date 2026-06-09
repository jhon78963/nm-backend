<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\WooCommerce\Services\WooCommerceSyncService;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use App\Models\Media;
use App\Shared\Foundation\Services\NodeUploaderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ProductMediaService
{
    public function __construct(
        private readonly NodeUploaderService $nodeUploaderService,
        private readonly ProductMediaUrlResolver $mediaUrlResolver,
        private readonly WooCommerceSyncService $wooCommerceSyncService,
    ) {}

    /**
     * @return array{
     *     media: array{id: int, filePath: string, publicUrl: string|null, fileName: string|null},
     *     wooCommerceSync: array{attempted: bool, products: int, variations: int, errors: int, error: string|null}
     * }
     */
    public function uploadAndSync(Product $product, UploadedFile $file): array
    {
        $path = $this->nodeUploaderService->upload($file, 'products');
        $media = $product->attachMedia($path, NodeUploaderService::sanitizeFilename($file));

        return [
            'media' => $this->formatMedia($product, $media),
            'wooCommerceSync' => $this->syncProductToWooCommerce((int) $product->id),
        ];
    }

    /**
     * @return array{body: string, content_type: string, filename: string}
     */
    public function stream(Product $product, Media $media): array
    {
        $this->assertMediaBelongsToProduct($product, $media);

        $path = (string) $media->file_path;

        if (! preg_match('#^/uploads/products/[a-f0-9\\-]+\\.(jpe?g|png|webp)$#i', $path)) {
            abort(403, 'Path de imagen no válido.');
        }

        $response = $this->nodeUploaderService->fetch($path);

        return [
            'body' => $response->body(),
            'content_type' => $response->header('Content-Type') ?? 'image/jpeg',
            'filename' => basename($path) ?: 'product-image.jpg',
        ];
    }

    /**
     * @return array{
     *     deletedMediaId: int,
     *     wooCommerceSync: array{attempted: bool, products: int, variations: int, errors: int, error: string|null}
     * }
     */
    public function deleteAndSync(Product $product, Media $media): array
    {
        $this->assertMediaBelongsToProduct($product, $media);

        $path = (string) $media->file_path;
        $mediaId = (int) $media->id;

        $this->nodeUploaderService->delete($path);
        $media->delete();

        return [
            'deletedMediaId' => $mediaId,
            'wooCommerceSync' => $this->syncProductToWooCommerce((int) $product->id),
        ];
    }

    private function assertMediaBelongsToProduct(Product $product, Media $media): void
    {
        if ($media->mediable_type !== Product::class || (int) $media->mediable_id !== (int) $product->id) {
            abort(404, 'La imagen no pertenece a este producto.');
        }
    }

    /**
     * @return array{id: int, filePath: string, publicUrl: string|null, fileName: string|null}
     */
    private function formatMedia(Product $product, Media $media): array
    {
        return [
            'id' => (int) $media->id,
            'filePath' => (string) $media->file_path,
            'publicUrl' => $this->mediaUrlResolver->previewApiUrl((int) $product->id, (int) $media->id),
            'fileName' => $media->file_name,
        ];
    }

    /**
     * @return array{attempted: bool, products: int, variations: int, errors: int, error: string|null}
     */
    private function syncProductToWooCommerce(int $productId): array
    {
        if (! config('woocommerce.enabled')) {
            return [
                'attempted' => false,
                'products' => 0,
                'variations' => 0,
                'errors' => 0,
                'error' => null,
            ];
        }

        try {
            $stats = $this->wooCommerceSyncService->syncProductById($productId);

            return [
                'attempted' => true,
                'products' => $stats['products'],
                'variations' => $stats['variations'],
                'errors' => $stats['errors'],
                'error' => $stats['errors'] > 0 ? 'WooCommerce sync finished with errors. Check logs.' : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('WooCommerce sync after product media change failed', [
                'product_id' => $productId,
                'message' => $e->getMessage(),
            ]);

            return [
                'attempted' => true,
                'products' => 0,
                'variations' => 0,
                'errors' => 1,
                'error' => $e->getMessage(),
            ];
        }
    }
}

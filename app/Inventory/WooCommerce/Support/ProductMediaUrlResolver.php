<?php

namespace App\Inventory\WooCommerce\Support;

use App\Models\Media;
use App\Inventory\Product\Models\Product;
use Illuminate\Support\Str;

/**
 * Convierte rutas del uploader-service (/uploads/...) en URLs absolutas
 * consumibles por WooCommerce al importar imágenes vía REST.
 */
final class ProductMediaUrlResolver
{
    /**
     * @return list<string> URLs absolutas en orden de galería (primera = thumbnail)
     */
    public function galleryUrlsForProduct(Product $product): array
    {
        if (! $product->relationLoaded('media')) {
            $product->load('media');
        }

        $urls = [];
        foreach ($product->media as $item) {
            $url = $this->absoluteUrl((string) $item->file_path);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{src: string, name: string, alt: string, position: int}>
     */
    public function wooCommerceImagesForProduct(Product $product): array
    {
        $urls = $this->galleryUrlsForProduct($product);

        return collect($urls)
            ->values()
            ->map(function (string $src, int $position) use ($product): array {
                $path = parse_url($src, PHP_URL_PATH);
                $name = is_string($path) ? basename($path) : "product-{$product->id}";

                return [
                    'src' => $src,
                    'name' => $name,
                    'alt' => Str::limit((string) $product->name, 120, ''),
                    'position' => $position,
                ];
            })
            ->all();
    }

    public function absoluteUrl(string $filePath): ?string
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return null;
        }

        if (Str::startsWith($filePath, ['http://', 'https://'])) {
            return $filePath;
        }

        $base = rtrim((string) (config('services.uploader.public_url') ?: config('services.uploader.url')), '/');
        if ($base === '') {
            return null;
        }

        $path = Str::startsWith($filePath, '/') ? $filePath : '/'.$filePath;

        return $base.$path;
    }

    /**
     * @return list<string> Rutas internas almacenadas en media.file_path
     */
    public function galleryPathsForProduct(Product $product): array
    {
        if (! $product->relationLoaded('media')) {
            $product->load('media');
        }

        return $product->media
            ->map(static fn (Media $media): string => (string) $media->file_path)
            ->filter(static fn (string $path): bool => $path !== '')
            ->values()
            ->all();
    }
}

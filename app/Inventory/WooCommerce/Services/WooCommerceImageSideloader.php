<?php

namespace App\Inventory\WooCommerce\Services;

use App\Shared\Foundation\Services\NodeUploaderService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cuando el uploader exige X-API-KEY, WooCommerce no puede descargar por URL.
 * Este servicio obtiene el binario con la API key y lo sube a la biblioteca WP.
 */
class WooCommerceImageSideloader
{
    public function __construct(
        private readonly NodeUploaderService $nodeUploaderService,
    ) {}

    /**
     * @param  list<string>  $filePaths  Rutas /uploads/... del uploader
     * @return list<array{id: int, src: string, name: string, alt: string, position: int}>
     */
    public function sideloadGallery(array $filePaths, string $altText): array
    {
        if ($filePaths === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'WOO_WP_APP_USER / WOO_WP_APP_PASSWORD requeridos para sideload de imágenes protegidas.',
            );
        }

        $images = [];
        foreach (array_values($filePaths) as $position => $filePath) {
            $response = $this->nodeUploaderService->fetch($filePath);
            $filename = basename($filePath) ?: "product-{$position}.jpg";

            $media = $this->uploadToWordPress(
                $response->body(),
                $filename,
                $this->mimeFromPath($filePath, $response->header('Content-Type')),
                $altText,
            );

            $images[] = [
                'id' => (int) $media['id'],
                'src' => (string) ($media['source_url'] ?? ''),
                'name' => $filename,
                'alt' => $altText,
                'position' => $position,
            ];
        }

        return $images;
    }

    public function isConfigured(): bool
    {
        return filled(config('woocommerce.wp_app_user'))
            && filled(config('woocommerce.wp_app_password'));
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadToWordPress(string $bytes, string $filename, string $mime, string $altText): array
    {
        $baseUrl = rtrim((string) config('woocommerce.base_url'), '/');
        $response = $this->wordpressClient()
            ->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post("{$baseUrl}/wp-json/wp/v2/media", [
                'title' => pathinfo($filename, PATHINFO_FILENAME),
                'alt_text' => $altText,
                'status' => 'publish',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Error subiendo imagen a WordPress (%s): HTTP %s — %s',
                $filename,
                $response->status(),
                $response->body(),
            ));
        }

        return (array) $response->json();
    }

    private function wordpressClient(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('woocommerce.wp_app_user'),
            (string) config('woocommerce.wp_app_password'),
        )
            ->acceptJson()
            ->timeout((int) config('woocommerce.timeout', 30))
            ->when(
                ! config('woocommerce.verify_ssl', true),
                static fn (PendingRequest $request) => $request->withoutVerifying(),
            );
    }

    private function mimeFromPath(string $path, ?string $header): string
    {
        if (is_string($header) && Str::startsWith($header, 'image/')) {
            return Str::before($header, ';');
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}

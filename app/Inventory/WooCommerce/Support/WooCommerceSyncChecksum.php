<?php

namespace App\Inventory\WooCommerce\Support;

/**
 * Hash estable del payload enviado a WooCommerce (detecta cambios reales).
 */
final class WooCommerceSyncChecksum
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): string
    {
        $normalized = [
            'name' => $payload['name'] ?? '',
            'slug' => $payload['slug'] ?? '',
            'status' => $payload['status'] ?? '',
            'description' => $payload['description'] ?? '',
            'short_description' => $payload['short_description'] ?? '',
            'sku' => $payload['sku'] ?? '',
            'category' => $payload['category']['name'] ?? '',
            'tags' => collect($payload['tags'] ?? [])->sort()->values()->all(),
            'attributes' => $payload['attributes'] ?? [],
            'image_paths' => $payload['image_paths'] ?? [],
            'variations' => collect($payload['variations'] ?? [])
                ->map(static fn (array $v): array => [
                    'variant_key' => $v['variant_key'] ?? '',
                    'sku' => $v['sku'] ?? '',
                    'regular_price' => $v['regular_price'] ?? '',
                    'stock_quantity' => $v['stock_quantity'] ?? 0,
                    'attributes' => $v['attributes'] ?? [],
                ])
                ->sortBy('variant_key')
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}

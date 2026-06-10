<?php

namespace App\Inventory\WooCommerce\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resuelve categorías WooCommerce (crea términos si no existen).
 * Tags: solo palabras clave editoriales; colores/tallas van en attributes.
 */
class WooCommerceTaxonomyResolver
{
    /** @var array<string, int> slug => term id */
    private array $categoryCache = [];

    /** @var array<string, int> slug => term id */
    private array $tagCache = [];

    /**
     * @param  array{gender_id?: int, name?: string}|null  $category
     * @return list<array{id: int}>
     */
    public function resolveCategories(?array $category): array
    {
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $slug = Str::slug($name);
        $id = $this->findOrCreateCategory($name, $slug, (int) ($category['gender_id'] ?? 0));

        return [['id' => $id]];
    }

    /**
     * @param  list<string>  $tagNames
     * @return list<array{id: int}>
     */
    public function resolveTags(array $tagNames): array
    {
        $out = [];

        foreach (array_values(array_unique(array_filter(array_map('trim', $tagNames)))) as $name) {
            if ($name === '') {
                continue;
            }

            $out[] = ['id' => $this->findOrCreateTag($name, Str::slug($name))];
        }

        return $out;
    }

    private function findOrCreateCategory(string $name, string $slug, int $genderId): int
    {
        if (isset($this->categoryCache[$slug])) {
            return $this->categoryCache[$slug];
        }

        $existing = $this->client()->get('products/categories', [
            'search' => $name,
            'slug' => $slug,
            'per_page' => 100,
        ]);

        if ($existing->successful()) {
            foreach ($existing->json() ?? [] as $term) {
                if (! is_array($term)) {
                    continue;
                }

                if ((string) ($term['slug'] ?? '') === $slug) {
                    return $this->categoryCache[$slug] = (int) $term['id'];
                }
            }
        }

        $meta = $genderId > 0
            ? [['key' => '_nm_gender_id', 'value' => (string) $genderId]]
            : [];

        $response = $this->client()->post('products/categories', [
            'name' => $name,
            'slug' => $slug,
            'meta_data' => $meta,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'No se pudo crear categoría WooCommerce "%s": HTTP %s — %s',
                $name,
                $response->status(),
                $response->body(),
            ));
        }

        $id = (int) $response->json('id');
        if ($id < 1) {
            throw new RuntimeException("Categoría WooCommerce sin ID válido: {$name}");
        }

        return $this->categoryCache[$slug] = $id;
    }

    private function findOrCreateTag(string $name, string $slug): int
    {
        if (isset($this->tagCache[$slug])) {
            return $this->tagCache[$slug];
        }

        $existing = $this->client()->get('products/tags', [
            'search' => $name,
            'slug' => $slug,
            'per_page' => 100,
        ]);

        if ($existing->successful()) {
            foreach ($existing->json() ?? [] as $term) {
                if (! is_array($term)) {
                    continue;
                }

                if ((string) ($term['slug'] ?? '') === $slug) {
                    return $this->tagCache[$slug] = (int) $term['id'];
                }
            }
        }

        $response = $this->client()->post('products/tags', [
            'name' => $name,
            'slug' => $slug,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'No se pudo crear etiqueta WooCommerce "%s": HTTP %s — %s',
                $name,
                $response->status(),
                $response->body(),
            ));
        }

        $id = (int) $response->json('id');
        if ($id < 1) {
            throw new RuntimeException("Etiqueta WooCommerce sin ID válido: {$name}");
        }

        return $this->tagCache[$slug] = $id;
    }

    private function client(): PendingRequest
    {
        $baseUrl = rtrim((string) config('woocommerce.base_url'), '/');

        return Http::baseUrl("{$baseUrl}/wp-json/wc/v3/")
            ->withBasicAuth(
                (string) config('woocommerce.consumer_key'),
                (string) config('woocommerce.consumer_secret'),
            )
            ->acceptJson()
            ->timeout((int) config('woocommerce.timeout', 120))
            ->when(
                ! config('woocommerce.verify_ssl', true),
                static fn (PendingRequest $request) => $request->withoutVerifying(),
            );
    }
}

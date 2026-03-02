<?php
namespace App\Inventory\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductEcommerceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variations = [];
        $uniqueColors = [];
        $uniqueSizes = [];
        $totalStock = 0;

        // Obtenemos el precio mínimo para mostrar en la tarjeta del producto
        $minPrice = $this->productSizes->min('sale_price') ?? 0;

        foreach ($this->productSizes as $productSize) {
            if (!isset($uniqueSizes[$productSize->size_id])) {
                $uniqueSizes[$productSize->size_id] = [
                    'id' => $productSize->size_id,
                    'value' => $productSize->size->description ?? '',
                    'attribute_id' => 14,
                ];
            }

            foreach ($productSize->colors as $color) {
                $stock = $color->pivot->stock;
                $totalStock += $stock;

                if (!isset($uniqueColors[$color->id])) {
                    $uniqueColors[$color->id] = [
                        'id' => $color->id,
                        'value' => $color->description,
                        'hex_color' => $color->hash,
                        'attribute_id' => 13,
                    ];
                }

                $variations[] = [
                    'id' => $productSize->id . '-' . $color->id,
                    'name' => "{$this->name} ({$color->description} / {$productSize->size->description})",
                    'price' => $productSize->purchase_price ?? $minPrice,
                    'sale_price' => $productSize->sale_price ?? $minPrice,
                    'quantity' => $stock,
                    'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                    'sku' => $productSize->barcode ?? $this->barcode,
                    'attribute_values' => [
                        ['id' => $color->id, 'value' => $color->description, 'attribute_id' => 13],
                        ['id' => $productSize->size_id, 'value' => $productSize->size->description, 'attribute_id' => 14]
                    ]
                ];
            }
        }

        $imageServerUrl = rtrim(env('IMAGE_SERVER_URL', 'http://localhost:8001'), '/');

        return [
            // DATOS REALES DE TU BD
            'id' => $this->id,
            'name' => $this->name,
            'description' => '<p>' . ($this->description ?? '') . '</p>',
            'price' => $minPrice,
            'sale_price' => $minPrice,
            'quantity' => $totalStock,
            'sku' => $this->barcode,
            'status' => $this->status === 'AVAILABLE' ? 1 : 0,
            'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
            'discount' => $this->percentage_discount,

            // IMÁGENES
            'product_thumbnail' => $this->imagesEcommerce->first() ? [
                'id' => $this->imagesEcommerce->first()->path,
                'name' => $this->imagesEcommerce->first()->name,
                'asset_url' => $imageServerUrl . '/storage/' . ltrim($this->imagesEcommerce->first()->path, '/')
            ] : null,

            'product_galleries' => $this->imagesEcommerce->map(function ($img) use ($imageServerUrl) {
                return [
                    'name' => $img->name,
                    'asset_url' => $imageServerUrl . '/storage/' . ltrim($img->path, '/')
                ];
            }),

            // === CAMPOS CALCULADOS PARA EVITAR ERRORES EN ANGULAR ===

            // Textos y URLs
            'slug' => Str::slug($this->name . '-' . $this->id),
            'short_description' => Str::limit(strip_tags($this->description ?? ''), 100),

            // Previene el TypeError: Cannot read properties of undefined (reading 'find')
            'wholesales' => [],

            // Previene errores con productos relacionados
            'cross_sell_products' => [],
            'related_products' => [],
            'cross_products' => [],
            'similar_products' => [],

            // Unidades y fechas (Angular las suele formatear)
            'unit' => '1 Unidad',
            'weight' => 0,
            'created_at' => $this->creation_time ?? now()->toIso8601String(),
            'updated_at' => $this->last_modification_time ?? now()->toIso8601String(),

            // Previene errores en componentes de valoración/estrellas
            'orders_count' => 0,
            'reviews_count' => 0,
            'rating_count' => 0,
            'can_review' => false,
            'review_ratings' => [0, 0, 0, 0, 0],

            // Previene el TypeError: Cannot read properties of undefined (reading 'toString')
            'store_id' => 1,
            'store' => [
                'id' => 1,
                'store_name' => 'Novedades Maritex',
                'slug' => 'novedades-maritex'
            ],

            // Relaciones de agrupamiento vacías por ahora
            'categories' => [],
            'brand' => null,
            'tags' => [],
            'reviews' => [],

            // Flags de interfaz (1 = true, 0 = false)
            'is_featured' => 1,
            'is_trending' => 0,
            'is_return' => 0,
            'is_approved' => 1,
            'type' => 'simple',

            // === ATRIBUTOS Y VARIACIONES ===
            'attributes' => [
                [
                    'id' => 13,
                    'name' => 'Colour',
                    'attribute_values' => array_values($uniqueColors)
                ],
                [
                    'id' => 14,
                    'name' => 'Size',
                    'attribute_values' => array_values($uniqueSizes)
                ]
            ],
            'variations' => $variations
        ];
    }
}

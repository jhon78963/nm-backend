<?php
namespace App\Inventory\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str; // Importamos Str para manipular textos

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

            // IMÁGENES (Tomamos la primera como thumbnail)
            'product_thumbnail' => $this->images->first() ? [
                'id' => $this->images->first()->path, // Usamos el path como ID temporal
                'name' => $this->images->first()->name,
                'asset_url' => asset('storage/' . $this->images->first()->path)
            ] : null,
            'product_galleries' => $this->images->map(function ($img) {
                return [
                    'name' => $img->name,
                    'asset_url' => asset('storage/' . $img->path)
                ];
            }),

            // === AQUI EMPIEZA LA MAGIA (DATOS CALCULADOS/SIMULADOS) ===

            // 1. Creamos un slug al vuelo basado en el nombre y el ID para que Angular pueda rutear
            'slug' => Str::slug($this->name . '-' . $this->id),

            // 2. Cortamos la descripción a 100 caracteres para la vista previa
            'short_description' => Str::limit(strip_tags($this->description ?? ''), 100),

            // 3. Devolvemos arrays vacíos o nulos para lo que aún no tienes, así Angular no da error 'undefined'
            'categories' => [],
            'brand' => null,
            'tags' => [],
            'reviews' => [],
            'is_featured' => 1, // Lo forzamos a 1 para que aparezca en tu inicio
            'is_trending' => 0,

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

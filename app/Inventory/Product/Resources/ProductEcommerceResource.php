<?php

namespace App\Inventory\Product\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductEcommerceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variations = [];
        $uniqueColors = [];
        $uniqueSizes = [];
        $totalStock = 0;
        $minPrice = $this->productSizes->min('sale_price') ?? 0;

        // Recorrer las tallas y sus colores para aplanar las variaciones
        foreach ($this->productSizes as $productSize) {

            // Recolectar tallas únicas para el bloque "attributes"
            if (!isset($uniqueSizes[$productSize->size_id])) {
                $uniqueSizes[$productSize->size_id] = [
                    'id' => $productSize->size_id,
                    'value' => $productSize->size->description ?? '',
                    'attribute_id' => 14, // Mantenemos el ID estático para "Size" según tu JSON
                ];
            }

            foreach ($productSize->colors as $color) {
                $stock = $color->pivot->stock;
                $totalStock += $stock;

                // Recolectar colores únicos para el bloque "attributes"
                if (!isset($uniqueColors[$color->id])) {
                    $uniqueColors[$color->id] = [
                        'id' => $color->id,
                        'value' => $color->description,
                        'hex_color' => $color->hash,
                        'attribute_id' => 13, // Mantenemos el ID estático para "Color"
                    ];
                }

                // Aplanar la variación (Talla + Color)
                $variations[] = [
                    'id' => $productSize->id . '-' . $color->id, // ID virtual compuesto
                    'name' => "{$this->name} ({$color->description} / {$productSize->size->description})",
                    'price' => $productSize->purchase_price,
                    'sale_price' => $productSize->sale_price,
                    'quantity' => $stock,
                    'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                    'sku' => $productSize->barcode,
                    'attribute_values' => [
                        [
                            'id' => $color->id,
                            'value' => $color->description,
                            'attribute_id' => 13
                        ],
                        [
                            'id' => $productSize->size_id,
                            'value' => $productSize->size->description,
                            'attribute_id' => 14
                        ]
                    ]
                ];
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_description' => strip_tags($this->description),
            'description' => '<p>' . $this->description . '</p>', // Renderizamos como HTML simple
            'price' => $minPrice,
            'sale_price' => $minPrice,
            'quantity' => $totalStock,
            'sku' => $this->barcode,
            'status' => $this->status === 'AVAILABLE' ? 1 : 0,
            'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
            'discount' => $this->percentage_discount,

            // Mapeo de la imagen principal (toma la primera)
            'product_thumbnail' => $this->imagesEcommerce->first() ? [
                'name' => $this->imagesEcommerce->first()->name,
                'asset_url' => asset('storage/' . $this->imagesEcommerce->first()->path)
            ] : null,

            // Mapeo de galerías
            'product_galleries' => $this->imagesEcommerce->map(function ($img) {
                return [
                    'name' => $img->name,
                    'asset_url' => asset('storage/' . $img->path)
                ];
            }),

            // Bloque de atributos agrupados
            'attributes' => [
                [
                    'id' => 13,
                    'name' => 'Colour',
                    'attribute_values' => array_values($uniqueColors) // array_values resetea los índices
                ],
                [
                    'id' => 14,
                    'name' => 'Size',
                    'attribute_values' => array_values($uniqueSizes)
                ]
            ],

            // Variaciones aplanadas
            'variations' => $variations
        ];
    }
}

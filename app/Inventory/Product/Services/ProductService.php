<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Builder;

class ProductService extends ModelService
{
    public function __construct(Product $product)
    {
        parent::__construct($product);
    }

 public function findBySkuForPos(string $barcode): ?array
    {
        // 1. Buscamos el producto cargando la ruta completa:
        // Product -> (hasMany) productSizes -> (belongsTo) size
        // Product -> (hasMany) productSizes -> (belongsToMany) productSizeColors
        $product = $this->model
            ->with([
                'productSizes.size',              // Para obtener el nombre "S", "M"
                'productSizes.productSizeColors'  // Para obtener los colores y su stock
            ])
            ->where('is_deleted', false)
            // Buscamos por el barcode del producto padre O de alguna de sus tallas
            ->where(function (Builder $query) use ($barcode) {
                $query->where('barcode', $barcode)
                      ->orWhereHas('productSizes', function ($q) use ($barcode) {
                          $q->where('barcode', $barcode);
                      });
            })
            ->first();

        if (!$product) {
            return null;
        }

        // 2. Transformar la data para el Frontend
        $variantsMap = [];
        $basePrice = 0;

        // Iteramos sobre los modelos ProductSize (no sobre el pivot genérico)
        foreach ($product->productSizes as $pSize) {

            // Verificar que tengamos objeto size cargado
            if (!$pSize->size) continue;

            $tallaNombre = $pSize->size->description; // Ej: "S"
            $currentPrice = (float) ($pSize->sale_price ?? 0); // PRECIO ESPECÍFICO DE LA TALLA

            // Tomamos el precio de la primera talla como base referencial (para mostrar "Desde S/...")
            if ($basePrice == 0) {
                $basePrice = $currentPrice;
            }

            // Inicializar array
            if (!isset($variantsMap[$tallaNombre])) {
                $variantsMap[$tallaNombre] = [];
            }

            $hasColorVariants = false;

            // A. Intentamos obtener variantes por COLOR (Stock detallado)
            foreach ($pSize->productSizeColors as $color) {
                // En belongsToMany, los datos de la tabla intermedia están en ->pivot
                $stock = $color->pivot->stock;

                if ($stock > 0) {
                    $hasColorVariants = true;
                    $variantsMap[$tallaNombre][] = [
                        // IDs compuestos necesarios para la venta
                        'product_size_id' => $pSize->id,
                        'color_id'        => $color->id,

                        // Data visual
                        'colorName'       => $color->description,
                        'hex'             => $color->hash ?? '#000000',
                        'stock'           => $stock,
                        'price'           => $currentPrice // <--- AQUÍ ESTÁ EL PRECIO REAL POR TALLA
                    ];
                }
            }

            // B. FALLBACK: Si no hay colores configurados, usamos el stock de la Talla (Stock general)
            // Esto soluciona tu problema donde "stock" => 7 está en ProductSize pero productSizeColors está vacío
            if (!$hasColorVariants && $pSize->stock > 0) {
                $variantsMap[$tallaNombre][] = [
                    'product_size_id' => $pSize->id,
                    'color_id'        => 0, // ID 0 indica "Sin color específico" o "Único"
                    'colorName'       => 'Único',
                    'hex'             => '#E5E7EB', // Un gris neutro
                    'stock'           => (int) $pSize->stock,
                    'price'           => $currentPrice // <--- AQUÍ TAMBIÉN
                ];
            }
        }

        return [
            'id'        => $product->id,
            'sku'       => $product->barcode ?? $barcode,
            'name'      => $product->name,
            'basePrice' => (float) $basePrice, // Precio referencial (el más bajo o el primero encontrado)
            'variants'  => $variantsMap
        ];
    }
}

<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Support\Facades\DB;

class ProductService extends ModelService
{
    public function __construct(Product $product)
    {
        parent::__construct($product);
    }

    public function findBySkuForPos(string $barcode): ?array
    {
        // ---------------------------------------------------------
        // FASE 1: BÚSQUEDA OPTIMIZADA (Para evitar colgar la BD)
        // ---------------------------------------------------------

        // Intento A: Buscar directamente por el código del Producto Principal
        $product = $this->model
            ->with([
                'productSizes.size',
                'productSizes.productSizeColors'
            ])
            ->where('barcode', $barcode)
            ->where('is_deleted', false)
            ->first();

        // Intento B: Si no es el padre, buscamos si ese código pertenece a una Talla específica
        if (!$product) {
            // Buscamos primero el ID del producto dueño de esa talla (Query ligera)
            // Asegúrate de que tu modelo de tallas sea ProductSize o ajusta el nombre de la clase
            $sizeMatch = ProductSize::where('barcode', $barcode)->first();

            if ($sizeMatch) {
                // Si encontramos la talla, cargamos el producto completo usando el ID encontrado
                $product = $this->model
                    ->with([
                        'productSizes.size',
                        'productSizes.productSizeColors'
                    ])
                    ->where('id', $sizeMatch->product_id) // Asumiendo que la FK es product_id
                    ->where('is_deleted', false)
                    ->first();
            }
        }

        // Si después de los dos intentos no hay producto, retornamos null
        if (!$product) {
            return null;
        }

        // ---------------------------------------------------------
        // FASE 2: MAPEO DE DATOS (Tu lógica original)
        // ---------------------------------------------------------
        $variantsMap = [];
        $basePrice = 0;

        foreach ($product->productSizes as $pSize) {

            // Si la talla no tiene relación 'size' (descripción), la saltamos para evitar errores
            if (!$pSize->size) {
                continue;
            }

            $tallaNombre = $pSize->size->description;
            $currentPrice = (float) ($pSize->sale_price ?? 0);

            // Usamos el barcode de la talla. Si es null, usamos string vacío para no romper el front
            $currentSku = $pSize->barcode ?? '';

            if ($basePrice == 0) {
                $basePrice = $currentPrice;
            }

            if (!isset($variantsMap[$tallaNombre])) {
                $variantsMap[$tallaNombre] = [];
            }

            $hasColorVariants = false;

            // A. Variantes por COLOR
            if ($pSize->productSizeColors && $pSize->productSizeColors->count() > 0) {
                foreach ($pSize->productSizeColors as $color) {
                    $stock = $color->pivot->stock;

                    if ($stock > 0) {
                        $hasColorVariants = true;
                        $variantsMap[$tallaNombre][] = [
                            'product_size_id' => $pSize->id,
                            'color_id'      => $color->id,
                            'colorName'     => $color->description,
                            'hex'           => $color->hash ?? '#000000',
                            'stock'         => $stock,
                            'price'         => $currentPrice,
                            'sku'           => $currentSku
                        ];
                    }
                }
            }

            // B. FALLBACK: "Único" (Si no tiene colores o todos tienen stock 0 pero la talla tiene stock general)
            // Nota: Agregué una validación extra aquí para asegurar que entre si no entró al loop de colores
            if (!$hasColorVariants && $pSize->stock > 0) {
                $variantsMap[$tallaNombre][] = [
                    'product_size_id' => $pSize->id,
                    'color_id'      => 0,
                    'colorName'     => 'Único',
                    'hex'           => '#E5E7EB',
                    'stock'         => (int) $pSize->stock,
                    'price'         => $currentPrice,
                    'sku'           => $currentSku
                ];
            }
        }

        return [
            'id'        => $product->id,
            'sku'       => $product->barcode ?? $barcode,
            'name'      => $product->name,
            'basePrice' => (float) $basePrice,
            'variants'  => $variantsMap
        ];
    }
}

<?php

namespace App\Inventory\Product\Jobs;

use App\Inventory\Product\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncSingleProductToMultikartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $productId;

    public function __construct($productId)
    {
        $this->productId = $productId;
    }

    public function handle(): void
    {
        // 1. Traemos la data fresca usando exactamente las relaciones funcionales
        $zProduct = Product::with(['productSizes.size', 'productSizes.colors', 'gender'])->find($this->productId);
        $mkDb = DB::connection('multikart');

        if (!$zProduct || $zProduct->is_deleted) {
            $mkDb->table('products')
                ->where('sku', 'Z' . $this->productId)
                ->update(['status' => 0, 'stock_status' => 'out_of_stock', 'quantity' => 0]);

            $mkDb->table('variations')
                ->where('sku', 'LIKE', '%Z' . $this->productId . '%')
                ->update(['status' => 0, 'stock_status' => 'out_of_stock', 'quantity' => 0]);
            return;
        }

        $adminId = 1;
        $storeId = 1;
        $colorAttrId = 1;
        $tallaAttrId = 2;
        $skuPadre = $zProduct->barcode ?? 'Z' . $zProduct->id;

        $totalStock = 0;
        $basePrice = 0;

        // Textos SEO por Género
        $generoZero = $zProduct->gender ? strtolower(trim($zProduct->gender->name)) : 'general';
        $tipoDesc = 'default';
        if (in_array($generoZero, ['dama', 'damas', 'femenino', 'mujer'])) $tipoDesc = 'dama';
        elseif (in_array($generoZero, ['caballero', 'caballeros', 'masculino', 'hombre'])) $tipoDesc = 'caballero';
        elseif (in_array($generoZero, ['niño', 'niños', 'niña', 'niñas'])) $tipoDesc = 'niño';

        $textosSeo = [
            'dama' => ['short' => 'Descubre la comodidad y estilo con nuestra nueva colección para damas.', 'long'  => 'Renueva tu armario con nuestras prendas exclusivas para mujer...'],
            'caballero' => ['short' => 'Estilo urbano y confort total para el hombre moderno.', 'long'  => 'Nuestra línea para caballeros combina durabilidad y diseño...'],
            'niño' => ['short' => 'Ropa divertida, resistente y súper cómoda para los más pequeños.', 'long'  => 'Sabemos que los niños no paran...'],
            'default' => ['short' => 'Calidad y comodidad garantizada para tu día a día.', 'long'  => 'Prenda confeccionada con los mejores materiales...']
        ];

        $nombreProductoJson = json_encode(['es' => $zProduct->name], JSON_UNESCAPED_UNICODE);
        $shortDescJson = json_encode(['es' => $textosSeo[$tipoDesc]['short']], JSON_UNESCAPED_UNICODE);
        $longDescJson  = json_encode(['es' => $textosSeo[$tipoDesc]['long']], JSON_UNESCAPED_UNICODE);

        // 2. ACTUALIZAR O CREAR EL PRODUCTO PADRE
        $mkProduct = $mkDb->table('products')->where('sku', $skuPadre)->first();

        if ($mkProduct) {
            $mkProductId = $mkProduct->id;
            // No podemos actualizar el stock total aquí todavía, porque necesitamos sumar el stock de las variaciones reales.
        } else {
            // INSERT (Esta parte se mantiene igual)
            $mkProductId = $mkDb->table('products')->insertGetId([
                'name' => $nombreProductoJson,
                'short_description' => $shortDescJson,
                'description' => $longDescJson,
                'sku' => $skuPadre,
                'type' => 'classified',
                'product_type' => 'physical',
                'price' => $basePrice,
                'sale_price' => $basePrice,
                'quantity' => 0, // Stock temporal
                'stock_status' => 'out_of_stock', // Stock temporal
                'status' => 1,
                'is_approved' => 1,
                'store_id' => $storeId,
                'tax_id' => 1,
                'created_by_id' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Categoría y Atributos (Esta parte se mantiene igual)
            $categoryIdsMap = ['dama' => 8, 'damas' => 8, 'mujer' => 8, 'caballero' => 10, 'caballeros' => 10, 'hombre' => 10, 'niño' => 14, 'niños' => 14];
            $categoryId = array_key_exists($generoZero, $categoryIdsMap) ? $categoryIdsMap[$generoZero] : 8;

            $mkDb->table('product_categories')->insert([
                'product_id' => $mkProductId, 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()
            ]);

            $mkDb->table('product_attributes')->insert([
                ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
            ]);
        }

        // 3. ACTUALIZAR O CREAR LAS VARIACIONES (REFINED LOGIC: SÓLO ITERAR COMBINACIONES EXISTENTES)
        foreach ($zProduct->productSizes as $pSize) {
            // Obtener valor de atributo de talla
            $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);
            $precio = $pSize->sale_price ?? 0;

            foreach ($pSize->colors as $pColor) {
                $stock = $pColor->pivot->stock;
                $totalStock += $stock; // Sumamos stock real

                // Obtener valor de atributo de color
                $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $pColor->description, $pColor->hash, $adminId);

                // Generar SKU funcional
                $skuVariacion = $pSize->barcode
                    ? $pSize->barcode . $pSize->id . $pColor->id
                    : $skuPadre . $pSize->id . $pColor->id;

                $nombreVariacion = $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $pColor->description;
                $nombreVariacionJson = json_encode(['es' => $nombreVariacion], JSON_UNESCAPED_UNICODE);

                $mkVariation = $mkDb->table('variations')->where('sku', $skuVariacion)->first();

                if ($mkVariation) {
                    // UPDATE: The variation exists, just update stock. This is the case for the user's issue with Crema.
                    $mkDb->table('variations')->where('id', $mkVariation->id)->update([
                        'name' => $nombreVariacionJson,
                        'price' => $precio,
                        'sale_price' => $precio,
                        'quantity' => $stock,
                        'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                        'status' => 1,
                        'updated_at' => now(),
                    ]);
                } else {
                    // INSERT: New combo, create it.
                    $variationId = $mkDb->table('variations')->insertGetId([
                        'product_id' => $mkProductId,
                        'name' => $nombreVariacionJson,
                        'price' => $precio,
                        'sale_price' => $precio,
                        'quantity' => $stock,
                        'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                        'sku' => $skuVariacion,
                        'status' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $mkDb->table('variation_attribute_values')->insert([
                        ['variation_id' => $variationId, 'attribute_value_id' => $tallaValueId, 'created_at' => now(), 'updated_at' => now()],
                        ['variation_id' => $variationId, 'attribute_value_id' => $colorValueId, 'created_at' => now(), 'updated_at' => now()],
                    ]);
                }
            }
        }

        // 4. ACTUALIZAR EL STOCK TOTAL DEL PRODUCTO PADRE (Ahora sí, con el total real)
        $mkDb->table('products')->where('id', $mkProductId)->update([
            'quantity' => $totalStock,
            'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
            'status' => $totalStock > 0 ? 1 : 0,
            'updated_at' => now(),
        ]);
    }

    private function getOrCreateAttributeValue($mkDb, $attributeId, $plainValue, $hexColor, $adminId)
    {
        $attrValue = $mkDb->table('attribute_values')->where('attribute_id', $attributeId)->where('value', 'LIKE', '%"' . $plainValue . '"%')->first();
        if (!$attrValue) $attrValue = $mkDb->table('attribute_values')->where('attribute_id', $attributeId)->where('value', $plainValue)->first();
        if ($attrValue) return $attrValue->id;

        return $mkDb->table('attribute_values')->insertGetId([
            'attribute_id' => $attributeId,
            'value' => json_encode(['es' => $plainValue], JSON_UNESCAPED_UNICODE),
            'hex_color' => $hexColor,
            'slug' => Str::slug($plainValue),
            'created_by_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}

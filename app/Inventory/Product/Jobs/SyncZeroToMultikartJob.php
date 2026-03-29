<?php

namespace App\Inventory\Product\Jobs; // Ojo: Asegúrate de que el namespace cuadre con tu ruta real si lo moviste a Jobs

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikartJob extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza con textos SEO por defecto y Nombres en JSON seguros :V';

    public function handle()
    {
        $this->info('Iniciando volcado final con descripciones y nombres seguros...');

        $zeroProducts = Product::with(['productSizes.size', 'productSizes.colors', 'gender'])->get();

        if ($zeroProducts->isEmpty()) {
            $this->warn('No hay productos en Zero para sincronizar.');
            return;
        }

        $mkDb = DB::connection('multikart');

        $adminId = 1;
        $storeId = 1;

        $colorAttrId = 1;
        $tallaAttrId = 2;

        $mkDb->beginTransaction();

        try {
            $this->info('Limpiando productos anteriores (Respetando el ID 5)...');
            $mkDb->table('products')->where('id', '!=', 5)->delete();

            $bar = $this->output->createProgressBar(count($zeroProducts));
            $bar->start();

            foreach ($zeroProducts as $zProduct) {

                $skuPadre = $zProduct->barcode ?? 'Z' . $zProduct->id;

                $totalStock = 0;
                $basePrice = 0;

                $validSizes = collect();
                $validColors = collect();

                foreach ($zProduct->productSizes as $pSize) {
                    $sizeStock = 0;
                    if ($basePrice === 0 && $pSize->sale_price > 0) {
                        $basePrice = $pSize->sale_price;
                    }
                    foreach ($pSize->colors as $pColor) {
                        $stock = $pColor->pivot->stock;
                        $totalStock += $stock;
                        $sizeStock += $stock;

                        if ($stock > 0 && !$validColors->contains('id', $pColor->id)) {
                            $validColors->push($pColor);
                        }
                    }

                    if ($sizeStock > 0) {
                        $validSizes->push($pSize);
                    }
                }

                // --- LÓGICA DE DESCRIPCIONES POR GÉNERO ---
                $generoZero = $zProduct->gender ? strtolower(trim($zProduct->gender->name)) : 'general';
                $tipoDesc = 'default';

                if (in_array($generoZero, ['dama', 'damas', 'femenino', 'mujer']))
                    $tipoDesc = 'dama';
                elseif (in_array($generoZero, ['caballero', 'caballeros', 'masculino', 'hombre']))
                    $tipoDesc = 'caballero';
                elseif (in_array($generoZero, ['niño', 'niños', 'niña', 'niñas']))
                    $tipoDesc = 'niño';

                $textosSeo = [
                    'dama' => [
                        'short' => 'Descubre la comodidad y estilo con nuestra nueva colección para damas.',
                        'long' => 'Renueva tu armario con nuestras prendas exclusivas para mujer. Diseñadas para ofrecer el máximo confort sin perder la elegancia. Ideales para el día a día, con materiales de alta calidad y un ajuste perfecto que resaltará tu estilo único.'
                    ],
                    'caballero' => [
                        'short' => 'Estilo urbano y confort total para el hombre moderno.',
                        'long' => 'Nuestra línea para caballeros combina durabilidad y diseño. Prendas versátiles que se adaptan a tu ritmo de vida, ya sea para un look casual de fin de semana o para estar cómodo en casa. Confeccionadas con materiales premium y costuras reforzadas.'
                    ],
                    'niño' => [
                        'short' => 'Ropa divertida, resistente y súper cómoda para los más pequeños.',
                        'long' => 'Sabemos que los niños no paran, por eso estas prendas están hechas para resistir todas sus aventuras. Telas suaves que cuidan su piel, colores vibrantes y diseños que les encantarán. ¡Libertad de movimiento total garantizada!'
                    ],
                    'default' => [
                        'short' => 'Calidad y comodidad garantizada para tu día a día.',
                        'long' => 'Prenda confeccionada con los mejores materiales para asegurar durabilidad y confort. Un básico imprescindible en tu guardarropa que combina con todo, pensado para ofrecerte la mejor experiencia de uso.'
                    ]
                ];

                // --- FIX DE TRADUCCIONES (NOMBRES Y DESCRIPCIONES A JSON) ---
                $nombreProductoJson = json_encode(['es' => $zProduct->name], JSON_UNESCAPED_UNICODE);
                $shortDescJson = json_encode(['es' => $textosSeo[$tipoDesc]['short']], JSON_UNESCAPED_UNICODE);
                $longDescJson = json_encode(['es' => $textosSeo[$tipoDesc]['long']], JSON_UNESCAPED_UNICODE);
                // -------------------------------------------------------------

                // 1. Insertar el Producto Padre
                $mkProductId = $mkDb->table('products')->insertGetId([
                    'name' => $nombreProductoJson, // <--- Aplicamos el JSON Seguro
                    'short_description' => $shortDescJson,
                    'description' => $longDescJson,
                    'sku' => $skuPadre,
                    'type' => 'classified',
                    'product_type' => 'physical',
                    'price' => $basePrice,
                    'sale_price' => $basePrice,
                    'quantity' => $totalStock,
                    'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
                    'status' => $totalStock > 0 ? 1 : 0,
                    'is_approved' => 1,
                    'store_id' => $storeId,
                    'tax_id' => 1,
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 2. CATEGORÍA
                if ($zProduct->gender) {
                    $categoryIdsMap = [
                        'dama' => 8, 'damas' => 8, 'femenino' => 8, 'mujer' => 8,
                        'caballero' => 10, 'caballeros' => 10, 'masculino' => 10, 'hombre' => 10,
                        'niño' => 14, 'niños' => 14, 'niña' => 14, 'niñas' => 14,
                    ];

                    $categoryId = array_key_exists($generoZero, $categoryIdsMap) ? $categoryIdsMap[$generoZero] : $this->getOrCreateCategory($mkDb, $zProduct->gender->name, $adminId);

                    $mkDb->table('product_categories')->insert([
                        'product_id' => $mkProductId,
                        'category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                // 3. Matriz de Variaciones
                if ($validSizes->isNotEmpty() && $validColors->isNotEmpty()) {

                    $mkDb->table('product_attributes')->insert([
                        ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                        ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                    ]);

                    foreach ($validSizes as $pSize) {
                        $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);
                        $precio = $pSize->sale_price ?? 0;

                        foreach ($validColors as $colorObj) {
                            $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $colorObj->description, $colorObj->hash, $adminId);
                            $colorPivot = $pSize->colors->firstWhere('id', $colorObj->id);
                            $stock = $colorPivot ? $colorPivot->pivot->stock : 0;

                            $skuVariacion = $pSize->barcode
                                ? $pSize->barcode . $pSize->id . $colorObj->id
                                : $skuPadre . $pSize->id . $colorObj->id;

                            // --- FIX DE TRADUCCIONES (NOMBRE VARIACIÓN A JSON) ---
                            $nombreVariacion = $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $colorObj->description;
                            $nombreVariacionJson = json_encode(['es' => $nombreVariacion], JSON_UNESCAPED_UNICODE);
                            // -----------------------------------------------------

                            $variationId = $mkDb->table('variations')->insertGetId([
                                'product_id' => $mkProductId,
                                'name' => $nombreVariacionJson, // <--- Aplicamos el JSON Seguro
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
                $bar->advance();
            }

            $mkDb->commit();
            $bar->finish();
            $this->newLine();
            $this->info('¡Sincronización completada! Base de datos curada contra bugs de JSON. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage() . ' en la línea ' . $e->getLine());
        }
    }

    private function getOrCreateCategory($mkDb, $name, $adminId)
    {
        $category = $mkDb->table('categories')->where('name', 'LIKE', '%"' . $name . '"%')->first();
        if (!$category) $category = $mkDb->table('categories')->where('name', $name)->first();
        if ($category) return $category->id;

        return $mkDb->table('categories')->insertGetId([
            'name' => json_encode(['es' => $name], JSON_UNESCAPED_UNICODE),
            'slug' => Str::slug($name),
            'type' => 'product',
            'status' => 1,
            'is_allow_all_zone' => 1,
            'created_by_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now()
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

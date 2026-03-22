<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikart extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza productos de Zero a Multikart incluyendo categorías sin duplicar :V';

    public function handle()
    {
        $this->info('Iniciando volcado final con categorías de Zero a Multikart...');

        // Agregamos 'gender' a la carga de relaciones para tener el dato a la mano
        $zeroProducts = Product::with(['productSizes.size', 'productSizes.colors', 'gender'])->get();

        if ($zeroProducts->isEmpty()) {
            $this->warn('No hay productos en Zero para sincronizar.');
            return;
        }

        $mkDb = DB::connection('multikart');

        $adminId = 1;
        $storeId = 1; // Tu tienda "Novedades Maritex"

        // IDs Fijos de Atributos según tu BD
        $colorAttrId = 1;
        $tallaAttrId = 2;

        $mkDb->beginTransaction();

        try {
            // LIMPIEZA: Borrar todos los productos EXCEPTO el ID 5
            $this->info('Limpiando basura anterior (Respetando el producto ID 5)...');
            $mkDb->table('products')->where('id', '!=', 5)->delete();

            $bar = $this->output->createProgressBar(count($zeroProducts));
            $bar->start();

            foreach ($zeroProducts as $zProduct) {

                $skuPadre = $zProduct->barcode ?? 'Z-' . $zProduct->id;

                // Calcular stock total y precio base
                $totalStock = 0;
                $basePrice = 0;

                foreach ($zProduct->productSizes as $pSize) {
                    if ($basePrice === 0 && $pSize->sale_price > 0) {
                        $basePrice = $pSize->sale_price;
                    }
                    foreach ($pSize->colors as $pColor) {
                        $totalStock += $pColor->pivot->stock;
                    }
                }

                // 1. Insertar el Producto Padre
                $mkProductId = $mkDb->table('products')->insertGetId([
                    'name' => $zProduct->name,
                    'short_description' => $zProduct->description,
                    'sku' => $skuPadre,
                    'type' => 'classified',
                    'product_type' => 'physical',
                    'price' => $basePrice,
                    'sale_price' => $basePrice,
                    'quantity' => $totalStock,
                    'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
                    'status' => $zProduct->status === 'AVAILABLE' ? 1 : 0,
                    'is_approved' => 1,
                    'store_id' => $storeId,
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 2. CATEGORÍA: Buscar o crear la categoría (Damas, Caballeros, etc.) y enlazarla
                $genderName = $zProduct->gender ? $zProduct->gender->name : 'General';
                $categoryId = $this->getOrCreateCategory($mkDb, $genderName, $adminId);

                $mkDb->table('product_categories')->insert([
                    'product_id' => $mkProductId,
                    'category_id' => $categoryId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // 3. Asociar el producto padre con los atributos Talla y Color
                $mkDb->table('product_attributes')->insert([
                    ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                    ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                ]);

                // 4. Recorrer Tallas y Colores de Zero para crear Variaciones
                foreach ($zProduct->productSizes as $pSize) {

                    $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);

                    foreach ($pSize->colors as $pColor) {

                        $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $pColor->description, $pColor->hash, $adminId);

                        $stock = $pColor->pivot->stock;
                        $precio = $pSize->sale_price ?? 0;

                        // 5. Insertar la Variación
                        $variationId = $mkDb->table('variations')->insertGetId([
                            'product_id' => $mkProductId,
                            'name' => $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $pColor->description,
                            'price' => $precio,
                            'sale_price' => $precio,
                            'quantity' => $stock,
                            'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                            'sku' => $pSize->barcode ?? 'VAR-' . $skuPadre . '-S' . $pSize->id . '-C' . $pColor->id,
                            'status' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // 6. Unir la Variación con Talla y Color
                        $mkDb->table('variation_attribute_values')->insert([
                            ['variation_id' => $variationId, 'attribute_value_id' => $tallaValueId, 'created_at' => now(), 'updated_at' => now()],
                            ['variation_id' => $variationId, 'attribute_value_id' => $colorValueId, 'created_at' => now(), 'updated_at' => now()],
                        ]);
                    }
                }
                $bar->advance();
            }

            $mkDb->commit();
            $bar->finish();
            $this->newLine();
            $this->info('¡Sincronización letal completada! Categorías en su sitio. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage() . ' en la línea ' . $e->getLine());
        }
    }

    /**
     * Helper para enlazar o crear las Categorías (Damas, Caballeros, etc.)
     */
    private function getOrCreateCategory($mkDb, $name, $adminId)
    {
        $category = $mkDb->table('categories')->where('name', $name)->first();

        if ($category) {
            return $category->id; // Si ya existe "Damas", devuelve su ID
        }

        // Si no existe, la crea como categoría de producto
        return $mkDb->table('categories')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => 'product', // Clave para que aparezca en la tienda y no como un blog post
            'status' => 1,
            'is_allow_all_zone' => 1,
            'created_by_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Helper para buscar los atributos (JSON support)
     */
    private function getOrCreateAttributeValue($mkDb, $attributeId, $plainValue, $hexColor, $adminId)
    {
        $attrValue = $mkDb->table('attribute_values')
            ->where('attribute_id', $attributeId)
            ->where('value', 'LIKE', '%"' . $plainValue . '"%')
            ->first();

        if (!$attrValue) {
            $attrValue = $mkDb->table('attribute_values')
                ->where('attribute_id', $attributeId)
                ->where('value', $plainValue)
                ->first();
        }

        if ($attrValue) {
            return $attrValue->id;
        }

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

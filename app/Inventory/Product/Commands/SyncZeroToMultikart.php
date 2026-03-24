<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikart extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza productos completando la matriz de variaciones con stock 0 :V';

    public function handle()
    {
        $this->info('Iniciando volcado final con relleno de matriz...');

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

                // Paso extra: Recopilar TODOS los colores únicos de este producto
                $allColors = collect();

                foreach ($zProduct->productSizes as $pSize) {
                    if ($basePrice === 0 && $pSize->sale_price > 0) {
                        $basePrice = $pSize->sale_price;
                    }
                    foreach ($pSize->colors as $pColor) {
                        $totalStock += $pColor->pivot->stock;

                        // Guardamos el color en nuestra colección si no está
                        if (!$allColors->contains('id', $pColor->id)) {
                            $allColors->push($pColor);
                        }
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
                    'status' => 1,
                    'is_approved' => 1,
                    'store_id' => $storeId,
                    'tax_id' => 1, // <--- AÑADIDO EL TAX ID
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 2. CATEGORÍA: Mapeo Directo por IDs
                if ($zProduct->gender) {
                    $generoZero = strtolower(trim($zProduct->gender->name));

                    $categoryIdsMap = [
                        'dama'       => 8,
                        'damas'      => 8,
                        'femenino'   => 8,
                        'mujer'      => 8,
                        'caballero'  => 10,
                        'caballeros' => 10,
                        'masculino'  => 10,
                        'hombre'     => 10,
                        'niño'       => 14,
                        'niños'      => 14,
                        'niña'       => 14,
                        'niñas'      => 14,
                    ];

                    if (array_key_exists($generoZero, $categoryIdsMap)) {
                        $categoryId = $categoryIdsMap[$generoZero];
                    } else {
                        $categoryId = $this->getOrCreateCategory($mkDb, $zProduct->gender->name, $adminId);
                    }

                    $mkDb->table('product_categories')->insert([
                        'product_id' => $mkProductId,
                        'category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                // 3. Asociar atributos base
                $mkDb->table('product_attributes')->insert([
                    ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                    ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                ]);

                // 4. Variaciones: Cruzamos TODAS las tallas con TODOS los colores (Matriz completa)
                foreach ($zProduct->productSizes as $pSize) {
                    $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);
                    $precio = $pSize->sale_price ?? 0;

                    foreach ($allColors as $colorObj) {
                        $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $colorObj->description, $colorObj->hash, $adminId);

                        // Verificamos si esta combinación exacta de talla/color existe en tu BD Zero
                        $colorPivot = $pSize->colors->firstWhere('id', $colorObj->id);

                        if ($colorPivot) {
                            // Sí existe: Tomamos el stock real
                            $stock = $colorPivot->pivot->stock;
                        } else {
                            // No existe: Rellenamos con 0 para que el panel de Multikart no se rompa
                            $stock = 0;
                        }

                        // SKU: Talla Barcode + Color ID (Solo números, sin guiones)
                        $skuVariacion = $pSize->barcode
                            ? $pSize->barcode . $colorObj->id
                            : $skuPadre . $pSize->id . $colorObj->id;

                        $variationId = $mkDb->table('variations')->insertGetId([
                            'product_id' => $mkProductId,
                            'name' => $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $colorObj->description,
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
                $bar->advance();
            }

            $mkDb->commit();
            $bar->finish();
            $this->newLine();
            $this->info('¡Sincronización completada! Matriz rellenada y panel a salvo. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage() . ' en la línea ' . $e->getLine());
        }
    }

    private function getOrCreateCategory($mkDb, $name, $adminId)
    {
        $category = $mkDb->table('categories')
            ->where('name', 'LIKE', '%"' . $name . '"%')
            ->first();

        if (!$category) {
            $category = $mkDb->table('categories')
                ->where('name', $name)
                ->first();
        }

        if ($category) {
            return $category->id;
        }

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

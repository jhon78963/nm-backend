<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikart extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza omitiendo colores sin stock total y aplicando matriz inteligente :V';

    public function handle()
    {
        $this->info('Iniciando volcado final con matriz filtrada anti-basura...');

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

                // Solo coleccionaremos las tallas y colores que tengan stock real > 0
                $validSizes = collect();
                $validColors = collect();

                foreach ($zProduct->productSizes as $pSize) {
                    $sizeStock = 0; // Para saber si esta talla existe en al menos 1 color
                    if ($basePrice === 0 && $pSize->sale_price > 0) {
                        $basePrice = $pSize->sale_price;
                    }
                    foreach ($pSize->colors as $pColor) {
                        $stock = $pColor->pivot->stock;
                        $totalStock += $stock;
                        $sizeStock += $stock;

                        // Solo metemos el color a la matriz si tiene stock > 0
                        if ($stock > 0 && !$validColors->contains('id', $pColor->id)) {
                            $validColors->push($pColor);
                        }
                    }

                    // Solo metemos la talla a la matriz si en total tiene stock > 0
                    if ($sizeStock > 0) {
                        $validSizes->push($pSize);
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

                // Si por si acaso el producto entero no tiene stock, ya no procesamos variaciones para no ensuciar
                if ($validSizes->isNotEmpty() && $validColors->isNotEmpty()) {

                    // 3. Asociar atributos base
                    $mkDb->table('product_attributes')->insert([
                        ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                        ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                    ]);

                    // 4. Variaciones: Matriz de datos LIMPIOS (solo colores/tallas con stock)
                    foreach ($validSizes as $pSize) {
                        $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);
                        $precio = $pSize->sale_price ?? 0;

                        foreach ($validColors as $colorObj) {
                            $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $colorObj->description, $colorObj->hash, $adminId);

                            $colorPivot = $pSize->colors->firstWhere('id', $colorObj->id);

                            if ($colorPivot) {
                                $stock = $colorPivot->pivot->stock;
                            } else {
                                $stock = 0;
                            }

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
                                'status' => $stock > 0 ? 1 : 0, // Aquí ponemos en 0 si la matriz cruzada da 0
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
            $this->info('¡Sincronización completada! Matriz limpia, sin colores fantasma y lista. 🚀');

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

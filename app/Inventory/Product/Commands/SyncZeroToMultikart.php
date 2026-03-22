<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikart extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza productos de Zero a Multikart limpiando previos duplicados :V';

    public function handle()
    {
        $this->info('Iniciando limpieza y volcado de datos de Zero a Multikart...');

        $zeroProducts = Product::with(['productSizes.size', 'productSizes.colors'])->get();

        if ($zeroProducts->isEmpty()) {
            $this->warn('No hay productos en Zero para sincronizar.');
            return;
        }

        $mkDb = DB::connection('multikart');

        $adminId = 1;
        $storeId = 1; // Tu tienda "Novedades Maritex"

        // IDs Fijos según tu BD
        $colorAttrId = 1;
        $tallaAttrId = 2;

        $mkDb->beginTransaction();

        try {
            // LIMPIEZA: Borrar todos los productos EXCEPTO el ID 5
            $this->info('Limpiando basura anterior (Respetando el producto de prueba ID 5)...');
            $mkDb->table('products')->where('id', '!=', 5)->delete();
            // El motor de base de datos se encargará de borrar las variaciones duplicadas en cascada

            $bar = $this->output->createProgressBar(count($zeroProducts));
            $bar->start();

            foreach ($zeroProducts as $zProduct) {

                $skuPadre = $zProduct->barcode ?? 'Z-' . $zProduct->id;

                // Calcular stock total y precio base para el producto padre (Para que se vea bien en el UI)
                $totalStock = 0;
                $basePrice = 0;

                foreach ($zProduct->productSizes as $pSize) {
                    // Tomar el primer precio de venta válido como base
                    if ($basePrice === 0 && $pSize->sale_price > 0) {
                        $basePrice = $pSize->sale_price;
                    }
                    // Sumar el stock de todos sus colores
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
                    'price' => $basePrice,         // Arregla el S/.0.00
                    'sale_price' => $basePrice,
                    'quantity' => $totalStock,     // Arregla el "-" de inventario
                    'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
                    'status' => $zProduct->status === 'AVAILABLE' ? 1 : 0, // Arregla el switch Estado
                    'is_approved' => 1,            // Arregla el switch Aprobar
                    'store_id' => $storeId,        // Arregla el "-" en Tienda
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 2. Asociar el producto padre con los atributos Talla y Color
                $mkDb->table('product_attributes')->insert([
                    ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                    ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                ]);

                // 3. Recorrer Tallas y Colores de Zero para crear Variaciones
                foreach ($zProduct->productSizes as $pSize) {

                    $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);

                    foreach ($pSize->colors as $pColor) {

                        $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $pColor->description, $pColor->hash, $adminId);

                        $stock = $pColor->pivot->stock;
                        $precio = $pSize->sale_price ?? 0;

                        // 4. Insertar la Variación
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

                        // 5. Unir la Variación con Talla y Color
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
            $this->info('¡Volcado completado con éxito! Interfaz limpia y con datos reales. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage() . ' en la línea ' . $e->getLine());
        }
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

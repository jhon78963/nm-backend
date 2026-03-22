<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncZeroToMultikart extends Command
{
    protected $signature = 'multikart:sync-initial';
    protected $description = 'Sincroniza todos los productos de Zero hacia la BD de Multikart con la estructura real :V';

    public function handle()
    {
        $this->info('Iniciando volcado de datos de Zero a Multikart...');

        $zeroProducts = Product::with(['productSizes.size', 'productSizes.colors'])->get();

        if ($zeroProducts->isEmpty()) {
            $this->warn('No hay productos en Zero para sincronizar.');
            return;
        }

        $mkDb = DB::connection('multikart');
        $adminId = 1;

        // IDs Fijos según tu Base de Datos de Multikart
        $colorAttrId = 1;
        $tallaAttrId = 2;

        $mkDb->beginTransaction();

        try {
            $bar = $this->output->createProgressBar(count($zeroProducts));
            $bar->start();

            foreach ($zeroProducts as $zProduct) {

                // 1. Insertar el Producto Padre como 'classified' (Variable Product en el UI)
                $mkProductId = $mkDb->table('products')->insertGetId([
                    'name' => $zProduct->name,
                    'short_description' => $zProduct->description,
                    'sku' => $zProduct->barcode ?? 'Z-' . $zProduct->id,
                    'type' => 'classified', // <--- ¡Dato clave del JSON!
                    'product_type' => 'physical',
                    'status' => $zProduct->status === 'AVAILABLE' ? 1 : 0,
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 2. Asociar el producto padre con los atributos Talla (2) y Color (1)
                $mkDb->table('product_attributes')->insert([
                    ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                    ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
                ]);

                // 3. Recorrer Tallas y Colores de Zero para crear Variaciones
                foreach ($zProduct->productSizes as $pSize) {

                    // Buscar o crear la Talla (Ej: "M", "32")
                    $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);

                    foreach ($pSize->colors as $pColor) {

                        // Buscar o crear el Color (Ej: "Negro", "#000000")
                        $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $pColor->description, $pColor->hash, $adminId);

                        $stock = $pColor->pivot->stock;
                        $precio = $pSize->sale_price ?? 0;

                        // 4. Insertar la Variación (El cruce)
                        $variationId = $mkDb->table('variations')->insertGetId([
                            'product_id' => $mkProductId,
                            'name' => $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $pColor->description,
                            'price' => $precio,
                            'sale_price' => $precio, // Añadido por si Multikart requiere que ambos estén llenos
                            'quantity' => $stock,
                            'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                            'sku' => $pSize->barcode ?? 'VAR-Z' . $zProduct->id . '-S' . $pSize->id . '-C' . $pColor->id,
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
            $this->info('¡Sincronización letal completada! Tus productos están en Multikart tal cual el JSON. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage() . ' en la línea ' . $e->getLine());
        }
    }

    /**
     * Helper para buscar los atributos soportando el formato JSON {"es": "Valor"}
     */
    private function getOrCreateAttributeValue($mkDb, $attributeId, $plainValue, $hexColor, $adminId)
    {
        // Multikart guarda los valores como JSON, ej: {"es":"Azul"}
        // Buscamos usando LIKE para ignorar la estructura estricta del JSON y encontrar el valor
        $attrValue = $mkDb->table('attribute_values')
            ->where('attribute_id', $attributeId)
            ->where('value', 'LIKE', '%"' . $plainValue . '"%')
            ->first();

        // Si por alguna razón Multikart guardó alguno como texto plano en el pasado (fallback)
        if (!$attrValue) {
            $attrValue = $mkDb->table('attribute_values')
                ->where('attribute_id', $attributeId)
                ->where('value', $plainValue)
                ->first();
        }

        if ($attrValue) {
            return $attrValue->id;
        }

        // Si no existe en tu maestro de Multikart, lo creamos forzando el formato JSON
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

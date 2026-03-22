<?php

namespace App\Inventory\Product\Commands;

use App\Inventory\Product\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncZeroToMultikart extends Command
{
    // El nombre que usarás en la terminal para ejecutarlo
    protected $signature = 'multikart:sync-initial';

    protected $description = 'Sincroniza todos los productos de Zero hacia la BD de Multikart por primera vez :V';

    public function handle()
    {
        $this->info('Iniciando volcado de datos de Zero a Multikart...');

        // Traemos todos tus productos con sus relaciones (ajusta los nombres de tus relaciones si varían)
        $zeroProducts = Product::with(['productSizes.size', 'productSizes.colors'])->get();

        if ($zeroProducts->isEmpty()) {
            $this->warn('No hay productos en Zero para sincronizar.');
            return;
        }

        // Usamos la conexión a multikart que configuraste en database.php
        $mkDb = DB::connection('multikart');

        // ID del usuario administrador en Multikart (por defecto suele ser 1)
        $adminId = 1;

        $mkDb->beginTransaction();

        try {
            // 1. Crear los Atributos Base en Multikart (Talla y Color) si no existen
            $tallaAttrId = $this->getOrCreateAttribute($mkDb, 'Talla', 'talla', $adminId);
            $colorAttrId = $this->getOrCreateAttribute($mkDb, 'Color', 'color', $adminId);

            $bar = $this->output->createProgressBar(count($zeroProducts));
            $bar->start();

            foreach ($zeroProducts as $zProduct) {
                // 2. Insertar el Producto Padre en Multikart
                $mkProductId = $mkDb->table('products')->insertGetId([
                    'name' => $zProduct->name,
                    'short_description' => $zProduct->description,
                    'sku' => $zProduct->barcode ?? 'Z-' . $zProduct->id,
                    'type' => 'simple',
                    'product_type' => 'physical',
                    'status' => $zProduct->status === 'AVAILABLE' ? 1 : 0,
                    'created_by_id' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Asociar producto con atributos Talla y Color en tabla pivote de Multikart
                $mkDb->table('product_attributes')->insert([
                    ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now()],
                    ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now()]
                ]);

                // 3. Recorrer Tallas y Colores de Zero para crear Variaciones
                foreach ($zProduct->productSizes as $pSize) {

                    // Crear o obtener el valor de la Talla (Ej: "M", "L")
                    $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);

                    foreach ($pSize->colors as $pColor) {

                        // Crear o obtener el valor del Color (Ej: "Rojo", con su Hash)
                        $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $pColor->description, $pColor->hash, $adminId);

                        $stock = $pColor->pivot->stock;

                        // 4. Insertar la Variación (El cruce exacto: Polo + M + Rojo)
                        $variationId = $mkDb->table('variations')->insertGetId([
                            'product_id' => $mkProductId,
                            'name' => $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $pColor->description,
                            'price' => $pSize->sale_price ?? 0,
                            'quantity' => $stock,
                            'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                            'sku' => $pSize->barcode ?? 'VAR-' . $zProduct->id . '-' . $pSize->id . '-' . $pColor->id,
                            'status' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // 5. Unir la Variación con sus Valores de Atributo (Talla y Color)
                        $mkDb->table('variation_attribute_values')->insert([
                            ['variation_id' => $variationId, 'attribute_value_id' => $tallaValueId, 'created_at' => now()],
                            ['variation_id' => $variationId, 'attribute_value_id' => $colorValueId, 'created_at' => now()],
                        ]);
                    }
                }
                $bar->advance();
            }

            $mkDb->commit();
            $bar->finish();
            $this->newLine();
            $this->info('¡Volcado completado con éxito! Todos tus productos están en Multikart. 🚀');

        } catch (\Exception $e) {
            $mkDb->rollBack();
            $this->error('Falló la sincronización: ' . $e->getMessage());
        }
    }

    // Funciones Helper para no repetir código de Query Builder

    private function getOrCreateAttribute($mkDb, $name, $slug, $adminId)
    {
        $attr = $mkDb->table('attributes')->where('name', $name)->first();
        if ($attr) return $attr->id;

        return $mkDb->table('attributes')->insertGetId([
            'name' => $name, 'slug' => $slug, 'status' => 1, 'created_by_id' => $adminId, 'created_at' => now()
        ]);
    }

    private function getOrCreateAttributeValue($mkDb, $attributeId, $value, $hexColor, $adminId)
    {
        $attrValue = $mkDb->table('attribute_values')
            ->where('attribute_id', $attributeId)->where('value', $value)->first();

        if ($attrValue) return $attrValue->id;

        return $mkDb->table('attribute_values')->insertGetId([
            'attribute_id' => $attributeId,
            'value' => $value,
            'hex_color' => $hexColor,
            'slug' => \Str::slug($value),
            'created_by_id' => $adminId,
            'created_at' => now()
        ]);
    }
}

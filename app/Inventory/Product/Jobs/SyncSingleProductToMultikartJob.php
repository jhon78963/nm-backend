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
        // 1. Traemos la data fresca de Zero
        $zProduct = Product::with(['productSizes.size', 'productSizes.colors', 'gender'])->find($this->productId);
        $mkDb = DB::connection('multikart');

        // Si eliminaron el producto en Zero, lo "apagamos" en Multikart
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

        // Textos SEO por Género (Igual que en el comando)
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

        $shortDescJson = json_encode(['es' => $textosSeo[$tipoDesc]['short']], JSON_UNESCAPED_UNICODE);
        $longDescJson  = json_encode(['es' => $textosSeo[$tipoDesc]['long']], JSON_UNESCAPED_UNICODE);

        // 2. BUSCAMOS EL PRODUCTO PADRE (Update or Insert)
        $mkProduct = $mkDb->table('products')->where('sku', $skuPadre)->first();

        if ($mkProduct) {
            $mkProductId = $mkProduct->id;
            $mkDb->table('products')->where('id', $mkProductId)->update([
                'name' => $zProduct->name,
                'short_description' => $shortDescJson,
                'description' => $longDescJson,
                'price' => $basePrice,
                'sale_price' => $basePrice,
                'quantity' => $totalStock,
                'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
                'status' => $totalStock > 0 ? 1 : 0,
                'updated_at' => now(),
            ]);
        } else {
            // Si no existe, lo creamos de cero
            $mkProductId = $mkDb->table('products')->insertGetId([
                'name' => $zProduct->name,
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

            // Como es nuevo, le amarramos la categoría
            $categoryIdsMap = ['dama' => 8, 'damas' => 8, 'mujer' => 8, 'caballero' => 10, 'caballeros' => 10, 'hombre' => 10, 'niño' => 14, 'niños' => 14];
            $categoryId = array_key_exists($generoZero, $categoryIdsMap) ? $categoryIdsMap[$generoZero] : 8; // Default 8

            $mkDb->table('product_categories')->insert([
                'product_id' => $mkProductId, 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()
            ]);

            $mkDb->table('product_attributes')->insert([
                ['product_id' => $mkProductId, 'attribute_id' => $tallaAttrId, 'created_at' => now(), 'updated_at' => now()],
                ['product_id' => $mkProductId, 'attribute_id' => $colorAttrId, 'created_at' => now(), 'updated_at' => now()]
            ]);
        }

        // 3. ACTUALIZAR O CREAR VARIACIONES
        if ($validSizes->isNotEmpty() && $validColors->isNotEmpty()) {
            foreach ($validSizes as $pSize) {
                $tallaValueId = $this->getOrCreateAttributeValue($mkDb, $tallaAttrId, $pSize->size->description, null, $adminId);
                $precio = $pSize->sale_price ?? 0;

                foreach ($validColors as $colorObj) {
                    $colorValueId = $this->getOrCreateAttributeValue($mkDb, $colorAttrId, $colorObj->description, $colorObj->hash, $adminId);
                    $colorPivot = $pSize->colors->firstWhere('id', $colorObj->id);
                    $stock = $colorPivot ? $colorPivot->pivot->stock : 0;

                    $skuVariacion = $pSize->barcode ? $pSize->barcode . $colorObj->id : $skuPadre . $pSize->id . $colorObj->id;

                    $mkVariation = $mkDb->table('variations')->where('sku', $skuVariacion)->first();

                    if ($mkVariation) {
                        // Si ya existe (Ej: Fue una venta), solo actualizamos stock y precio
                        $mkDb->table('variations')->where('id', $mkVariation->id)->update([
                            'price' => $precio,
                            'sale_price' => $precio,
                            'quantity' => $stock,
                            'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                            'status' => $stock > 0 ? 1 : 0,
                            'updated_at' => now(),
                        ]);
                    } else {
                        // Si es un COLOR NUEVO que acabas de agregar en Zero, lo inserta!
                        $variationId = $mkDb->table('variations')->insertGetId([
                            'product_id' => $mkProductId,
                            'name' => $zProduct->name . ' - ' . $pSize->size->description . ' - ' . $colorObj->description,
                            'price' => $precio,
                            'sale_price' => $precio,
                            'quantity' => $stock,
                            'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                            'sku' => $skuVariacion,
                            'status' => $stock > 0 ? 1 : 0,
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
        }
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

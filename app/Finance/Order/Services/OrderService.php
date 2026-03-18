<?php

namespace App\Finance\Order\Services;

use App\Finance\Order\Models\Order;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderService extends ModelService
{
    public function __construct(
        Order $order,
        protected ProductSizeService $productSizeService
    ) {
        parent::__construct($order);
    }

    // public function processOrder(array $data): Order
    // {
    //     return DB::transaction(function () use ($data) {
    //         $order = $this->create([
    //             'reference_date' => $data['reference_date'],
    //             'code' => 'O-' . time(),
    //             'total_amount' => $data['total'],
    //             'origin_warehouse_id' => $data['origin_warehouse_id'] ?? null,
    //             'destination_warehouse_id' => $data['destination_warehouse_id'],
    //             'tracking_number' => $data['tracking_number'] ?? null,
    //             'type' => $data['type'],
    //             'status' => $data['status'] ?? 'COMPLETED',
    //             'notes' => $data['notes'],
    //         ]);

    //         foreach ($data['items'] as $item) {
    //             $productSizeId = $item['product_size_id'];
    //             $qty = $item['quantity'];

    //             // 1. Obtener Stock ANTES de la orden (Para el historial)
    //             $currentStock = 0;
    //             if ($item['color_id'] > 0) {
    //                 $colorRow = DB::table('product_size_color')
    //                     ->where('product_size_id', $productSizeId)
    //                     ->where('color_id', $item['color_id'])
    //                     ->first();
    //                 $currentStock = $colorRow ? $colorRow->stock : 0;
    //             } else {
    //                 $masterRow = DB::table('product_size')->where('id', $productSizeId)->first();
    //                 $currentStock = $masterRow ? $masterRow->stock : 0;
    //             }

    //             // 2. Aumento de Stock
    //             $product = Product::findOrFail($item['product_id']);
    //             $pivotData = [
    //                 'barcode' => $item['barcode'],
    //                 'purchase_price' => $item['purchase_price'],
    //                 'sale_price' => $item['sale_price'],
    //                 'min_sale_price' => $item['min_sale_price'],
    //             ];
    //             $this->productSizeService->setStock(
    //                 $product,
    //                 $item['size_id'],
    //                 $qty,
    //                 $pivotData,
    //             );

    //             if ($item['color_id'] > 0) {
    //                 DB::table('product_size_color')
    //                     ->where('product_size_id', $productSizeId)
    //                     ->where('color_id', $item['color_id'])
    //                     ->increment('stock', $qty);
    //             }

    //             // 3. Recuperar nombres
    //             $sizeInfo = DB::table('product_size')
    //                 ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
    //                 ->join('products', 'product_size.product_id', '=', 'products.id')
    //                 ->where('product_size.id', $productSizeId)
    //                 ->select('products.id as pid', 'products.name as pname', 'sizes.description as sname', 'sizes.id as sid')
    //                 ->first();

    //             $colorName = $item['color_id'] > 0
    //                 ? (DB::table('colors')->where('id', $item['color_id'])->value('description') ?? 'Desconocido')
    //                 : 'Único';

    //             // 4. Guardar detalle de la orden
    //             $order->details()->create([
    //                 'product_id' => $sizeInfo->pid,
    //                 'size_id' => $sizeInfo->sid,
    //                 'color_id' => $item['color_id'] > 0 ? $item['color_id'] : null,
    //                 'product_name_snapshot' => $sizeInfo->pname,
    //                 'size_name_snapshot' => $sizeInfo->sname,
    //                 'color_name_snapshot' => $colorName,
    //                 'quantity' => $qty,
    //                 'barcode' => $item['barcode'],
    //                 'purchase_price' => $item['purchase_price'],
    //                 'sale_price' => $item['sale_price'],
    //                 'min_sale_price' => $item['min_sale_price'],
    //                 'subtotal' => $item['total']
    //             ]);

    //             // 5. HISTORIAL DETALLADO (Con Stock Antes y Después)
    //             ProductHistory::create([
    //                 'product_id' => $sizeInfo->pid,
    //                 'entity_type' => 'ORDER',
    //                 'entity_id' => $order->id,
    //                 'event_type' => 'TAKEN',
    //                 'old_values' => [
    //                     'stock' => $currentStock // Stock Antes
    //                 ],
    //                 'new_values' => [
    //                     'stock' => $currentStock - $qty, // Stock Después
    //                     'quantity' => $qty,
    //                     'unit_price' => $item['unit_price'],
    //                     'order_code' => $order->code,
    //                     'size_name' => $sizeInfo->sname,
    //                     'color_name' => $colorName
    //                 ],
    //                 'creator_user_id' => Auth::id(),
    //             ]);
    //         }
    //         return $order;
    //     });
    // }


    public function processOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = $this->create([
                'reference_date' => $data['reference_date'],
                'code' => 'O-' . time(),
                'total_amount' => $data['total'],
                'origin_warehouse_id' => $data['origin_warehouse_id'] ?? null,
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'tracking_number' => $data['tracking_number'] ?? null,
                'type' => $data['type'],
                'status' => $data['status'] ?? 'COMPLETED',
                'notes' => $data['notes'] ?? null, // Le agregué el ?? null por seguridad
            ]);

            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                $sizeId = $item['size_id'];
                $colorId = $item['color_id'] ?? null;
                $qty = $item['quantity'];

                // 1. Instanciar el Producto (esto debe existir sí o sí)
                $product = Product::findOrFail($productId);

                // 2. Buscar si la talla YA existe en este producto
                $productSize = ProductSize::where('product_id', $productId)
                    ->where('size_id', $sizeId)
                    ->first();

                // 3. Obtener Stock ANTES de la orden (Para el historial)
                $currentStock = 0;
                if ($productSize) {
                    if ($colorId > 0) {
                        $colorRow = DB::table('product_size_color')
                            ->where('product_size_id', $productSize->id)
                            ->where('color_id', $colorId)
                            ->first();
                        $currentStock = $colorRow ? $colorRow->stock : 0;
                    } else {
                        $currentStock = $productSize->stock;
                    }
                }

                // 4. Aumento de Stock (Esto CREA el product_size si es nuevo, o lo actualiza)
                $pivotData = [
                    'barcode' => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price' => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                ];
                $this->productSizeService->setStock(
                    $product,
                    $sizeId,
                    $qty,
                    $pivotData,
                );

                // 5. IMPORTANTE: Recuperar el ProductSize fresco
                // Si no existía, la función setStock lo acaba de crear y necesitamos su nuevo ID
                if (!$productSize) {
                    $productSize = ProductSize::where('product_id', $productId)
                        ->where('size_id', $sizeId)
                        ->first();
                }

                // 6. Actualizar stock por color (Protegido contra colores nuevos)
                if ($colorId > 0) {
                    $colorRowExists = DB::table('product_size_color')
                        ->where('product_size_id', $productSize->id)
                        ->where('color_id', $colorId)
                        ->exists();

                    if ($colorRowExists) {
                        DB::table('product_size_color')
                            ->where('product_size_id', $productSize->id)
                            ->where('color_id', $colorId)
                            ->increment('stock', $qty);
                    } else {
                        // Si es un color nuevo para esta talla, lo insertamos
                        DB::table('product_size_color')->insert([
                            'product_size_id' => $productSize->id,
                            'color_id' => $colorId,
                            'stock' => $qty
                        ]);
                    }
                }

                // 7. Recuperar nombres directos de las tablas (Sin depender de relaciones frágiles)
                $sizeName = DB::table('sizes')->where('id', $sizeId)->value('description') ?? 'Desconocida';
                $colorName = $colorId > 0
                    ? (DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido')
                    : 'Único';

                // 8. Guardar detalle de la orden
                $order->details()->create([
                    'product_id' => $productId,
                    'size_id' => $sizeId,
                    'color_id' => $colorId > 0 ? $colorId : null,
                    'product_name_snapshot' => $product->name,
                    'size_name_snapshot' => $sizeName,
                    'color_name_snapshot' => $colorName,
                    'quantity' => $qty,
                    'barcode' => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price' => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                    'subtotal' => $item['total'] ?? ($qty * $item['purchase_price']) // Por si no mandas el total en el item
                ]);

                // 9. HISTORIAL DETALLADO
                ProductHistory::create([
                    'product_id' => $productId,
                    'entity_type' => 'ORDER',
                    'entity_id' => $order->id,
                    'event_type' => 'ADDED', // Cambié a ADDED asumiendo que es ingreso a almacén
                    'old_values' => [
                        'stock' => $currentStock
                    ],
                    'new_values' => [
                        'stock' => $currentStock + $qty, // ¡OJO AQUÍ! Lo cambié a + $qty porque es un ingreso
                        'quantity' => $qty,
                        'unit_price' => $item['unit_price'] ?? $item['purchase_price'], // Fallback por si acaso
                        'order_code' => $order->code,
                        'size_name' => $sizeName,
                        'color_name' => $colorName
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }
            return $order;
        });
    }

    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model) {
            // 1. Cargar los detalles si no están cargados
            $model->load('details');

            foreach ($model->details as $detail) {
                // Obtener el ID de la relación product_size
                $psId = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->value('id');

                if ($psId) {
                    // 2. Capturar stock actual ANTES de devolver (para el historial)
                    $currentStock = 0;
                    if ($detail->color_id) {
                        $colorRow = DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->first();
                        $currentStock = $colorRow ? $colorRow->stock : 0;

                        // Devolver stock a la tabla por color
                        DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->decrement('stock', $detail->quantity);
                    } else {
                        $masterRow = DB::table('product_size')->where('id', $psId)->first();
                        $currentStock = $masterRow ? $masterRow->stock : 0;
                    }

                    // Devolver stock a la tabla maestra
                    DB::table('product_size')->where('id', $psId)->decrement('stock', $detail->quantity);

                    // 3. Registrar en Historial de Producto
                    ProductHistory::create([
                        'product_id' => $detail->product_id,
                        'entity_type' => 'ORDER_VOIDED', // O 'ORDER'
                        'entity_id' => $model->id,
                        'event_type' => 'RETURNED',
                        'old_values' => [
                            'stock' => $currentStock
                        ],
                        'new_values' => [
                            'stock' => $currentStock + $detail->quantity,
                            'quantity' => $detail->quantity,
                            'reason' => 'Venta anulada por el usuario',
                            'order_code' => $model->code
                        ],
                        'creator_user_id' => Auth::id(),
                    ]);
                }
            }

            // 4. Marcar la venta como eliminada y cambiar estado
            // Asumiendo que usas 'is_deleted' según tus queries de estadísticas
            $model->update([
                'is_deleted' => true,
                'status' => 'CANCELED', // O 'CANCELED'
                'notes' => substr(($model->notes ?? '') . " | VENTA ANULADA EL " . now(), -250)
            ]);
        });
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {

            // 1. Actualizar campos base
            $model->fill($data);
            $model->save();

            // 2. Actualizar Precios de Ítems
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $detail = $model->details()->where('id', $itemData['id'])->first();
                    if ($detail) {
                        $quantity = (int) $itemData['quantity'];
                        $total = $quantity * (float)$itemData['purchase_price'];
                        $detail->update([
                            'quantity' => $quantity,
                            'barcode' => $itemData['barcode'],
                            'purchase_price' => $itemData['purchase_price'],
                            'sale_price' => $itemData['sale_price'],
                            'min_sale_price' => $itemData['min_sale_price'],
                            'subtotal' => $total,
                        ]);
                    }
                }

                // Recalcular Total General
                $newGrandTotal = $model->details()->sum('subtotal');
                $model->update(['total_amount' => $newGrandTotal]);
            }

            return $model->fresh(['details']);
        });
    }
}

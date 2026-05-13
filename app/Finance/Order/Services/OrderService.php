<?php

namespace App\Finance\Order\Services;

use App\Finance\Order\Models\Order;
use App\Finance\Order\Models\OrderDetail;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

            $items = $data['items'] ?? [];
            $productIdsForSort = collect($items)
                ->pluck('product_id')
                ->unique()
                ->filter()
                ->values()
                ->all();

            $psIdByPair = [];
            if ($productIdsForSort !== []) {
                foreach (DB::table('product_size')
                    ->whereIn('product_id', $productIdsForSort)
                    ->get(['id', 'product_id', 'size_id']) as $pse) {
                    $pairKey = sprintf('%s:%s', $pse->product_id, $pse->size_id);
                    $psIdByPair[$pairKey] = (int) $pse->id;
                }
            }

            $resolveProcessOrderProductSizeId = static function (array $item, array $psMap): int {
                if (isset($item['product_size_id']) && (int) $item['product_size_id'] > 0) {
                    return (int) $item['product_size_id'];
                }

                $pid = (int) ($item['product_id'] ?? 0);
                $sid = (int) ($item['size_id'] ?? 0);
                $psId = $psMap[sprintf('%d:%d', $pid, $sid)] ?? null;

                return $psId !== null ? (int) $psId : PHP_INT_MAX;
            };

            usort(
                $items,
                fn (array $a, array $b): int => $resolveProcessOrderProductSizeId($a, $psIdByPair)
                    <=> $resolveProcessOrderProductSizeId($b, $psIdByPair),
            );

            foreach ($items as $item) {
                $productId = $item['product_id'];
                $sizeId = $item['size_id'];
                $colorId = $item['color_id'] ?? null;
                $qty = (int) $item['quantity'];

                $product = Product::findOrFail($productId);

                $productSize = ProductSize::where('product_id', $productId)
                    ->where('size_id', $sizeId)
                    ->first();

                $pivotData = [
                    'barcode' => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price' => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                ];

                // Crear talla en catálogo si no existe (sin mover stock aún); el incremento va bajo bloqueo.
                if (! $productSize) {
                    $this->productSizeService->setStock($product, $sizeId, 0, $pivotData);
                    $productSize = ProductSize::where('product_id', $productId)
                        ->where('size_id', $sizeId)
                        ->firstOrFail();
                }

                $psId = (int) $productSize->id;

                $masterLocked = DB::table('product_size')->where('id', $psId)->lockForUpdate()->first();
                if ($masterLocked === null) {
                    throw new RuntimeException(
                        'No se encontró la talla del producto para registrar la orden.'
                    );
                }

                DB::table('product_size')->where('id', $psId)->update([
                    'barcode' => $pivotData['barcode'],
                    'purchase_price' => $pivotData['purchase_price'],
                    'sale_price' => $pivotData['sale_price'],
                    'min_sale_price' => $pivotData['min_sale_price'],
                ]);

                $currentStock = 0;
                $colorPivotRow = null;

                if ($colorId > 0) {
                    $colorPivotRow = DB::table('product_size_color')
                        ->where('product_size_id', $psId)
                        ->where('color_id', $colorId)
                        ->lockForUpdate()
                        ->first();
                    $currentStock = $colorPivotRow ? (int) $colorPivotRow->stock : 0;
                } else {
                    $masterRow = DB::table('product_size')->where('id', $psId)->first();
                    $currentStock = (int) $masterRow->stock;
                }

                DB::table('product_size')->where('id', $psId)->increment('stock', $qty);

                if ($colorId > 0) {
                    if ($colorPivotRow !== null) {
                        DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $colorId)
                            ->increment('stock', $qty);
                    } else {
                        DB::table('product_size_color')->insert([
                            'product_size_id' => $psId,
                            'color_id' => $colorId,
                            'stock' => $qty,
                        ]);
                    }
                }

                // Recuperar nombres directos de las tablas (Sin depender de relaciones frágiles)
                $sizeName = DB::table('sizes')->where('id', $sizeId)->value('description') ?? 'Desconocida';
                $colorName = $colorId > 0
                    ? (DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido')
                    : 'Único';

                // Guardar detalle de la orden
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

                // Historial de ingreso (orden)
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

            $details = $model->details;
            $productIdsForSort = $details->pluck('product_id')->unique()->filter()->values()->all();

            $psIdByPair = [];
            if ($productIdsForSort !== []) {
                foreach (DB::table('product_size')
                    ->whereIn('product_id', $productIdsForSort)
                    ->get(['id', 'product_id', 'size_id']) as $pse) {
                    $pairKey = sprintf('%s:%s', $pse->product_id, $pse->size_id);
                    $psIdByPair[$pairKey] = (int) $pse->id;
                }
            }

            $details = $details
                ->sort(function ($a, $b) use ($psIdByPair): int {
                    $psa = $psIdByPair[sprintf('%s:%s', $a->product_id, $a->size_id)] ?? PHP_INT_MAX;
                    $psb = $psIdByPair[sprintf('%s:%s', $b->product_id, $b->size_id)] ?? PHP_INT_MAX;

                    return $psa <=> $psb;
                })
                ->values();

            foreach ($details as $detail) {
                $qty = (int) $detail->quantity;

                $masterRow = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->lockForUpdate()
                    ->first();

                if (! $masterRow) {
                    continue;
                }

                $psId = (int) $masterRow->id;

                // Stock bajo bloqueo (maestro primero, pivot después) antes de decrementar y auditar.
                $currentStock = 0;
                if ($detail->color_id) {
                    $colorRow = DB::table('product_size_color')
                        ->where('product_size_id', $psId)
                        ->where('color_id', $detail->color_id)
                        ->lockForUpdate()
                        ->first();
                    $currentStock = $colorRow ? (int) $colorRow->stock : 0;

                    DB::table('product_size')->where('id', $psId)->decrement('stock', $qty);

                    if ($colorRow) {
                        DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->decrement('stock', $qty);
                    }
                } else {
                    $currentStock = (int) $masterRow->stock;

                    DB::table('product_size')->where('id', $psId)->decrement('stock', $qty);
                }

                // 3. Registrar en Historial de Producto
                ProductHistory::create([
                    'product_id' => $detail->product_id,
                    'entity_type' => 'ORDER_VOIDED', // O 'ORDER'
                    'entity_id' => $model->id,
                    'event_type' => 'RETURNED',
                    'old_values' => [
                        'stock' => $currentStock,
                    ],
                    'new_values' => [
                        'stock' => $currentStock - $qty,
                        'quantity' => $qty,
                        'reason' => 'Orden de ingreso anulada',
                        'order_code' => $model->code,
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }

            // 4. Marcar la orden como eliminada y cambiar estado
            // Asumiendo que usas 'is_deleted' según tus queries de estadísticas
            $model->update([
                'is_deleted' => true,
                'status' => 'CANCELED', // O 'CANCELED'
                'notes' => substr(($model->notes ?? '') . " | ORDEN DE INGRESO ANULADA EL " . now(), -250)
            ]);
        });
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {

            // 1. Actualizar campos base
            $model->fill($data);
            $model->save();

            // 2. Actualizar Precios de Ítems (y stock si cambia la cantidad)
            if (!empty($data['items']) && is_array($data['items'])) {
                $items = $this->sortOrderUpdateItemsByProductSizeId($model, $data['items']);
                foreach ($items as $itemData) {
                    $detail = $model->details()->where('id', $itemData['id'])->first();
                    if (!$detail) {
                        continue;
                    }

                    $oldQty = (int) $detail->quantity;
                    $newQty = (int) $itemData['quantity'];
                    $delta = $newQty - $oldQty;

                    $purchasePrice = array_key_exists('purchase_price', $itemData)
                        ? (float) $itemData['purchase_price']
                        : (float) $detail->purchase_price;

                    $pivotData = [
                        'barcode' => $itemData['barcode'] ?? $detail->barcode,
                        'purchase_price' => $purchasePrice,
                        'sale_price' => array_key_exists('sale_price', $itemData)
                            ? (float) $itemData['sale_price']
                            : (float) $detail->sale_price,
                        'min_sale_price' => array_key_exists('min_sale_price', $itemData)
                            ? (float) $itemData['min_sale_price']
                            : (float) $detail->min_sale_price,
                    ];

                    if ($delta !== 0) {
                        $this->applyOrderDetailStockDelta($detail, $delta, $pivotData);
                    }

                    $total = $newQty * $purchasePrice;
                    $detail->update([
                        'quantity' => $newQty,
                        'barcode' => $pivotData['barcode'],
                        'purchase_price' => $pivotData['purchase_price'],
                        'sale_price' => $pivotData['sale_price'],
                        'min_sale_price' => $pivotData['min_sale_price'],
                        'subtotal' => $total,
                    ]);
                }

                // Recalcular Total General
                $newGrandTotal = $model->details()->sum('subtotal');
                $model->update(['total_amount' => $newGrandTotal]);
            }

            return $model->fresh(['details']);
        });
    }

    /**
     * Orden ascendente únicamente por `product_size.id`: payload `product_size_id` válido (>0) o lookup del par del detalle.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortOrderUpdateItemsByProductSizeId(Model $order, array $items): array
    {
        $items = array_values($items);
        usort(
            $items,
            fn (array $a, array $b): int => $this->resolveOrderUpdateItemSortKeyProductSizeId($order, $a)
                <=> $this->resolveOrderUpdateItemSortKeyProductSizeId($order, $b),
        );

        return $items;
    }

    private function resolveOrderUpdateItemSortKeyProductSizeId(Model $order, array $itemData): int
    {
        $detail = $order->details()->where('id', $itemData['id'] ?? 0)->first();
        if (! $detail) {
            return PHP_INT_MAX;
        }

        if (isset($itemData['product_size_id'])) {
            $fromPayload = (int) $itemData['product_size_id'];
            if ($fromPayload > 0) {
                return $fromPayload;
            }
        }

        $psId = DB::table('product_size')
            ->where('product_id', $detail->product_id)
            ->where('size_id', $detail->size_id)
            ->value('id');

        return $psId !== null ? (int) $psId : PHP_INT_MAX;
    }

    /**
     * Ajusta inventario por cambio de cantidad en un detalle de orden (ingreso).
     * Replica la lógica de processOrder: pivot product_size + product_size_color cuando hay color.
     */
    private function applyOrderDetailStockDelta(OrderDetail $detail, int $delta, array $pivotData): void
    {
        if ($delta === 0) {
            return;
        }

        $product = Product::findOrFail($detail->product_id);

        if ($delta < 0) {
            $psId = DB::table('product_size')
                ->where('product_id', $detail->product_id)
                ->where('size_id', $detail->size_id)
                ->lockForUpdate()
                ->value('id');

            if (!$psId) {
                throw new RuntimeException(
                    'No se encontró inventario para revertir el ajuste de cantidad en la orden.'
                );
            }

            $need = abs($delta);

            if ($detail->color_id) {
                $colorRow = DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $detail->color_id)
                    ->lockForUpdate()
                    ->first();

                $colorStock = $colorRow ? (int) $colorRow->stock : 0;
                if ($colorStock < $need) {
                    throw new RuntimeException(
                        'Stock insuficiente en color para reducir la cantidad del ítem de la orden.'
                    );
                }
            }

            // Maestro ya bloqueado arriba; lectura sin re-bloqueo redundante.
            $masterRow = DB::table('product_size')->where('id', $psId)->first();
            $masterStock = $masterRow ? (int) $masterRow->stock : 0;
            if ($masterStock < $need) {
                throw new RuntimeException(
                    'Stock insuficiente para reducir la cantidad del ítem de la orden.'
                );
            }
        } elseif ($delta > 0) {
            $psId = DB::table('product_size')
                ->where('product_id', $detail->product_id)
                ->where('size_id', $detail->size_id)
                ->lockForUpdate()
                ->value('id');

            if (! $psId) {
                $this->productSizeService->setStock(
                    $product,
                    (int) $detail->size_id,
                    0,
                    $pivotData,
                );
                $psId = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->lockForUpdate()
                    ->value('id');
            }

            if (! $psId) {
                throw new RuntimeException(
                    'No se pudo crear o resolver product_size para aumentar la cantidad del ítem de la orden.'
                );
            }

            if ($detail->color_id) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $detail->color_id)
                    ->lockForUpdate()
                    ->first();
            }
        }

        $this->productSizeService->setStock(
            $product,
            (int) $detail->size_id,
            $delta,
            $pivotData,
        );

        $productSize = ProductSize::where('product_id', $detail->product_id)
            ->where('size_id', $detail->size_id)
            ->first();

        if (!$productSize) {
            throw new RuntimeException('No se pudo resolver product_size tras el ajuste de stock.');
        }

        $colorId = $detail->color_id ? (int) $detail->color_id : 0;

        if ($colorId > 0) {
            if ($delta > 0) {
                $colorRowExists = DB::table('product_size_color')
                    ->where('product_size_id', $productSize->id)
                    ->where('color_id', $colorId)
                    ->exists();

                if ($colorRowExists) {
                    DB::table('product_size_color')
                        ->where('product_size_id', $productSize->id)
                        ->where('color_id', $colorId)
                        ->increment('stock', $delta);
                } else {
                    DB::table('product_size_color')->insert([
                        'product_size_id' => $productSize->id,
                        'color_id' => $colorId,
                        'stock' => $delta,
                    ]);
                }
            } else {
                DB::table('product_size_color')
                    ->where('product_size_id', $productSize->id)
                    ->where('color_id', $colorId)
                    ->decrement('stock', abs($delta));
            }
        }
    }
}

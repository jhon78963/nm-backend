<?php

namespace App\Finance\Order\Services;

use App\Finance\Order\Models\Order;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeService;
use App\Inventory\Support\InventoryManager;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderService extends ModelService
{
    public function __construct(
        Order $order,
        protected ProductSizeService $productSizeService,
        protected InventoryManager $inventory,
    ) {
        parent::__construct($order);
    }

    /**
     * Crea una nueva orden de compra: ingresa stock y registra historial.
     */
    public function processOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = $this->create([
                'reference_date'         => $data['reference_date'],
                'code'                   => 'O-' . time(),
                'total_amount'           => $data['total'],
                'origin_warehouse_id'    => $data['origin_warehouse_id'] ?? null,
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'tracking_number'        => $data['tracking_number'] ?? null,
                'type'                   => $data['type'],
                'status'                 => $data['status'] ?? 'COMPLETED',
                'notes'                  => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                $sizeId    = $item['size_id'];
                $colorId   = (int) ($item['color_id'] ?? 0) > 0 ? (int) $item['color_id'] : null;
                $qty       = (int) $item['quantity'];

                $product     = Product::findOrFail($productId);
                $productSize = ProductSize::where('product_id', $productId)
                    ->where('size_id', $sizeId)
                    ->first();

                // Capturar stock antes (para historial) antes de setStock()
                $currentStock = 0;
                if ($productSize) {
                    $currentStock = $colorId
                        ? (int) (DB::table('product_size_color')
                            ->where('product_size_id', $productSize->id)
                            ->where('color_id', $colorId)
                            ->value('stock') ?? 0)
                        : (int) $productSize->stock;
                }

                // setStock() crea o actualiza product_size con precios
                $pivotData = [
                    'barcode'        => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price'     => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                ];
                $this->productSizeService->setStock($product, $sizeId, $qty, $pivotData);

                // Si product_size era nuevo, recuperar el registro recién creado
                if (!$productSize) {
                    $productSize = ProductSize::where('product_id', $productId)
                        ->where('size_id', $sizeId)
                        ->firstOrFail();
                }

                if ($colorId) {
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
                        DB::table('product_size_color')->insert([
                            'product_size_id' => $productSize->id,
                            'color_id'        => $colorId,
                            'stock'           => $qty,
                        ]);
                    }
                }

                $sizeName  = DB::table('sizes')->where('id', $sizeId)->value('description') ?? 'Desconocida';
                $colorName = $colorId
                    ? (DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido')
                    : 'Único';

                $order->details()->create([
                    'product_id'            => $productId,
                    'size_id'               => $sizeId,
                    'color_id'              => $colorId,
                    'product_name_snapshot' => $product->name,
                    'size_name_snapshot'    => $sizeName,
                    'color_name_snapshot'   => $colorName,
                    'quantity'              => $qty,
                    'barcode'               => $item['barcode'],
                    'purchase_price'        => $item['purchase_price'],
                    'sale_price'            => $item['sale_price'],
                    'min_sale_price'        => $item['min_sale_price'],
                    'subtotal'              => $item['total'] ?? ($qty * $item['purchase_price']),
                ]);

                $this->inventory->recordHistory(
                    productId:   $productId,
                    entityType:  'ORDER',
                    entityId:    $order->id,
                    eventType:   'ADDED',
                    stockBefore: $currentStock,
                    stockAfter:  $currentStock + $qty,
                    extra: [
                        'quantity'   => $qty,
                        'unit_price' => $item['unit_price'] ?? $item['purchase_price'],
                        'order_code' => $order->code,
                        'size_name'  => $sizeName,
                        'color_name' => $colorName,
                    ]
                );
            }

            return $order;
        });
    }

    /**
     * Edita una orden existente: actualiza precios, cantidades y sincroniza
     * el delta de stock en inventario.
     *
     * CORRECCIÓN: la versión anterior solo actualizaba la fila del detalle sin
     * tocar el inventario. Ahora calcula el delta (newQty - oldQty) y aplica
     * increment/decrement según corresponda, con bloqueo de fila incluido.
     */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {
            $model->fill($data);
            $model->save();

            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $detail = $model->details()->where('id', $itemData['id'])->first();
                    if (!$detail) {
                        continue;
                    }

                    $oldQty = (int) $detail->quantity;
                    $newQty = (int) $itemData['quantity'];
                    $delta  = $newQty - $oldQty;

                    if ($delta !== 0) {
                        $psId = $this->inventory->resolveProductSizeId(
                            $detail->product_id,
                            $detail->size_id
                        );

                        if ($psId) {
                            $colorId = $detail->color_id;

                            // Las órdenes son ingresos: más cantidad → más stock.
                            if ($delta > 0) {
                                $stockBefore = $this->inventory->increment($psId, $colorId, $delta);
                            } else {
                                $stockBefore = $this->inventory->decrement($psId, $colorId, abs($delta));
                            }

                            $this->inventory->recordHistory(
                                productId:   $detail->product_id,
                                entityType:  'ORDER_EDIT',
                                entityId:    $model->id,
                                eventType:   $delta > 0 ? 'ADDED' : 'TAKEN',
                                stockBefore: $stockBefore,
                                stockAfter:  $stockBefore + $delta,
                                extra: [
                                    'quantity_old' => $oldQty,
                                    'quantity_new' => $newQty,
                                    'reason'       => 'Ajuste por edición de orden',
                                    'order_code'   => $model->code,
                                ]
                            );
                        }
                    }

                    $detail->update([
                        'quantity'       => $newQty,
                        'barcode'        => $itemData['barcode'],
                        'purchase_price' => $itemData['purchase_price'],
                        'sale_price'     => $itemData['sale_price'],
                        'min_sale_price' => $itemData['min_sale_price'],
                        'subtotal'       => $newQty * (float) $itemData['purchase_price'],
                    ]);
                }

                $model->update(['total_amount' => $model->details()->sum('subtotal')]);
            }

            return $model->fresh(['details']);
        });
    }

    /**
     * Anula una orden de compra: revierte el stock ingresado y registra historial.
     *
     * CORRECCIONES:
     * - El stock se DECREMENTA al anular una orden (devolver el ingreso),
     *   y el historial refleja esa resta correctamente.
     * - El reason dice "Orden anulada" (no "Venta anulada").
     * - Se usa InventoryManager para garantizar lockForUpdate en ambos niveles.
     */
    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model) {
            $model->load('details');

            foreach ($model->details as $detail) {
                $psId = $this->inventory->resolveProductSizeId(
                    $detail->product_id,
                    $detail->size_id
                );

                if (!$psId) {
                    continue;
                }

                // decrement() bloquea, valida y retorna stock antes de la operación.
                $stockBefore = $this->inventory->decrement(
                    $psId,
                    $detail->color_id,
                    $detail->quantity
                );

                $this->inventory->recordHistory(
                    productId:   $detail->product_id,
                    entityType:  'ORDER_VOIDED',
                    entityId:    $model->id,
                    eventType:   'RETURNED',
                    stockBefore: $stockBefore,
                    stockAfter:  $stockBefore - $detail->quantity,
                    extra: [
                        'quantity'   => $detail->quantity,
                        'reason'     => 'Orden anulada',
                        'order_code' => $model->code,
                    ]
                );
            }

            $model->update([
                'is_deleted' => true,
                'status'     => 'CANCELED',
                'notes'      => substr(
                    ($model->notes ?? '') . ' | ORDEN ANULADA EL ' . now()->format('d/m/Y H:i'),
                    -250
                ),
            ]);
        });
    }
}

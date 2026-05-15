<?php

namespace App\Finance\Order\Services;

use App\Finance\Order\Models\Order;
use App\Finance\Order\Models\OrderDetail;
use App\Inventory\Concerns\AssertsInventoryMasterMatchesColorPivotSum;
use App\Inventory\Concerns\ProvidesInventoryLockSortKey;
use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Services\ProductSizeService;
use App\Inventory\Support\StockAvailability;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService extends ModelService
{
    use AssertsInventoryMasterMatchesColorPivotSum;
    use ProvidesInventoryLockSortKey;

    public function __construct(
        Order $order,
        protected ProductSizeService $productSizeService,
        protected InventoryMovementService $inventoryMovementService,
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
            $touchedMasterIds = [];

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

            $items = array_values($data['items'] ?? []);
            usort(
                $items,
                fn (array $a, array $b): int => $this->getInventoryLockSortKey(
                    (int) ($a['product_id'] ?? 0),
                    (int) ($a['size_id'] ?? 0),
                ) <=> $this->getInventoryLockSortKey(
                    (int) ($b['product_id'] ?? 0),
                    (int) ($b['size_id'] ?? 0),
                ),
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
                    $this->productSizeService->set($product, $sizeId, [
                        'barcode' => $pivotData['barcode'],
                        'purchasePrice' => $pivotData['purchase_price'],
                        'salePrice' => $pivotData['sale_price'],
                        'minSalePrice' => $pivotData['min_sale_price'],
                        'stock' => 0,
                    ]);
                    $productSize = ProductSize::where('product_id', $productId)
                        ->where('size_id', $sizeId)
                        ->firstOrFail();
                }

                $psId = (int) $productSize->id;
                $touchedMasterIds[] = $psId;

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

                if ($colorId > 0) {
                    DB::table('product_size_color')->updateOrInsert([
                        'product_size_id' => $psId,
                        'color_id' => $colorId,
                    ]);
                }

                $warehouseId = (int) $order->destination_warehouse_id;
                $tenantId = $this->resolveTenantId($warehouseId);
                $currentStock = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $colorId > 0 ? (int) $colorId : null);
                $this->recordOrderInventoryMovement($warehouseId, $tenantId, $psId, $colorId > 0 ? (int) $colorId : null, InventoryMovementDirection::In, $qty, (int) $order->id);
                $newStockDb = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $colorId > 0 ? (int) $colorId : null);

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

                // Historial de ingreso (stock final = lectura post-mutación)
                ProductHistory::create([
                    'product_id' => $productId,
                    'entity_type' => 'ORDER',
                    'entity_id' => $order->id,
                    'event_type' => 'ADDED', // Cambié a ADDED asumiendo que es ingreso a almacén
                    'old_values' => [
                        'stock' => $currentStock
                    ],
                    'new_values' => [
                        'stock' => $newStockDb,
                        'quantity' => $qty,
                        'unit_price' => $item['unit_price'] ?? $item['purchase_price'], // Fallback por si acaso
                        'order_code' => $order->code,
                        'size_name' => $sizeName,
                        'color_name' => $colorName
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }

            $uniqueMasterIds = array_unique($touchedMasterIds);
            foreach ($uniqueMasterIds as $masterId) {
                $this->assertMasterMatchesColorsSum((int) $masterId);
            }

            return $order;
        });
    }

    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model) {
            $touchedMasterIds = [];

            // 1. Cargar los detalles si no están cargados
            $model->load('details');

            $details = $model->details
                ->sort(function ($a, $b): int {
                    return $this->getInventoryLockSortKey((int) $a->product_id, (int) $a->size_id)
                        <=> $this->getInventoryLockSortKey((int) $b->product_id, (int) $b->size_id);
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
                $warehouseId = (int) $model->destination_warehouse_id;
                $tenantId = $this->resolveTenantId($warehouseId);
                $colorId = $detail->color_id ? (int) $detail->color_id : null;
                $currentStock = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $colorId);
                StockAvailability::assertCanDecrement($currentStock, $qty);
                $this->recordOrderInventoryMovement($warehouseId, $tenantId, $psId, $colorId, InventoryMovementDirection::Out, $qty, (int) $model->id);
                $newStockDb = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $colorId);

                // 3. Registrar en Historial de Producto (stock final = lectura post-mutación)
                ProductHistory::create([
                    'product_id' => $detail->product_id,
                    'entity_type' => 'ORDER_VOIDED', // O 'ORDER'
                    'entity_id' => $model->id,
                    'event_type' => 'RETURNED',
                    'old_values' => [
                        'stock' => $currentStock,
                    ],
                    'new_values' => [
                        'stock' => $newStockDb,
                        'quantity' => $qty,
                        'reason' => 'Orden de ingreso anulada',
                        'order_code' => $model->code,
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }

            $uniqueMasterIds = array_unique($touchedMasterIds);
            foreach ($uniqueMasterIds as $masterId) {
                $this->assertMasterMatchesColorsSum((int) $masterId);
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
            $touchedMasterIds = [];

            // 1. Actualizar campos base
            $model->fill($data);
            $model->save();

            // 2. Actualizar Precios de Ítems (y stock si cambia la cantidad)
            if (!empty($data['items']) && is_array($data['items'])) {
                $items = $this->sortOrderUpdateItemsByProductSizePair($model, $data['items']);
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
                        $this->applyOrderDetailStockDelta($model, $detail, $delta, $pivotData, $touchedMasterIds);
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

            $uniqueMasterIds = array_unique($touchedMasterIds);
            foreach ($uniqueMasterIds as $masterId) {
                $this->assertMasterMatchesColorsSum((int) $masterId);
            }

            return $model->fresh(['details']);
        });
    }

    /**
     * Anti-deadlock: orden estricto por par (product_id, size_id) del detalle, sin depender de product_size.id.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortOrderUpdateItemsByProductSizePair(Model $order, array $items): array
    {
        $items = array_values($items);
        usort(
            $items,
            fn (array $a, array $b): int => $this->resolveOrderUpdateItemLockSortKey($order, $a)
                <=> $this->resolveOrderUpdateItemLockSortKey($order, $b),
        );

        return $items;
    }

    private function resolveOrderUpdateItemLockSortKey(Model $order, array $itemData): string
    {
        $detail = $order->details()->where('id', $itemData['id'] ?? 0)->first();
        if (! $detail) {
            return $this->getInventoryLockSortKey(2147483647, 2147483647);
        }

        return $this->getInventoryLockSortKey((int) $detail->product_id, (int) $detail->size_id);
    }

    /**
     * Ajusta inventario por cambio de cantidad en un detalle de orden (ingreso).
     * Replica la lógica de processOrder: pivot product_size + product_size_color cuando hay color.
     */
    private function applyOrderDetailStockDelta(Model $order, OrderDetail $detail, int $delta, array $pivotData, array &$touchedMasterIds): void
    {
        if ($delta === 0) {
            return;
        }

        $colorIdForStock = $detail->color_id ? (int) $detail->color_id : 0;

        $masterRow = DB::table('product_size')
            ->where('product_id', $detail->product_id)
            ->where('size_id', $detail->size_id)
            ->lockForUpdate()
            ->first();

        if ($delta < 0) {
            if (! $masterRow) {
                throw new RuntimeException(
                    'No se encontró inventario para revertir el ajuste de cantidad en la orden.'
                );
            }

        } else {
            if (! $masterRow) {
                $product = Product::findOrFail($detail->product_id);
                $this->productSizeService->set($product, (int) $detail->size_id, [
                    'barcode' => $pivotData['barcode'],
                    'purchasePrice' => $pivotData['purchase_price'],
                    'salePrice' => $pivotData['sale_price'],
                    'minSalePrice' => $pivotData['min_sale_price'],
                    'stock' => 0,
                ]);
                $masterRow = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->first();
            }

            if (! $masterRow) {
                throw new RuntimeException(
                    'No se pudo crear o resolver product_size para aumentar la cantidad del ítem de la orden.'
                );
            }

        }

        $psId = (int) $masterRow->id;

        $colorId = $colorIdForStock;

        if ($colorId > 0) {
            DB::table('product_size_color')->updateOrInsert([
                'product_size_id' => $psId,
                'color_id' => $colorId,
            ]);
        }

        $warehouseId = (int) $order->destination_warehouse_id;
        $tenantId = $this->resolveTenantId($warehouseId);
        $ledgerColorId = $colorIdForStock > 0 ? $colorIdForStock : null;
        $oldStock = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $ledgerColorId);
        $direction = $delta > 0 ? InventoryMovementDirection::In : InventoryMovementDirection::Out;
        $quantity = abs($delta);
        if ($direction === InventoryMovementDirection::Out) {
            StockAvailability::assertCanDecrement($oldStock, $quantity);
        }
        $this->recordOrderInventoryMovement($warehouseId, $tenantId, $psId, $ledgerColorId, $direction, $quantity, (int) $order->getKey());
        $newStock = $this->inventoryMovementService->getAvailableQuantity($warehouseId, $psId, $ledgerColorId);

        $sizeName = DB::table('sizes')->where('id', $detail->size_id)->value('description') ?? 'Desconocida';
        $colorName = $colorIdForStock > 0
            ? (DB::table('colors')->where('id', $colorIdForStock)->value('description') ?? 'Desconocido')
            : 'Único';

        ProductHistory::create([
            'product_id' => (int) $detail->product_id,
            'entity_type' => 'ORDER_UPDATE',
            'entity_id' => (int) $order->getKey(),
            'event_type' => $delta > 0 ? 'ADDED' : 'RETURNED',
            'old_values' => [
                'stock' => $oldStock,
            ],
            'new_values' => [
                'stock' => $newStock,
                'quantity_delta' => $delta,
                'purchase_price' => $pivotData['purchase_price'],
                'order_code' => (string) ($order->getAttribute('code') ?? ''),
                'order_detail_id' => (int) $detail->id,
                'product_size_id' => $psId,
                'color_id' => $colorIdForStock > 0 ? $colorIdForStock : null,
                'size_name' => $sizeName,
                'color_name' => $colorName,
                'reason' => 'Ajuste por edición de cantidad en Orden de Ingreso',
            ],
            'creator_user_id' => Auth::id(),
        ]);

        $touchedMasterIds[] = $psId;
    }

    private function resolveTenantId(int $warehouseId): int
    {
        return (int) Warehouse::query()->findOrFail($warehouseId)->tenant_id;
    }

    private function recordOrderInventoryMovement(
        int $warehouseId,
        int $tenantId,
        int $productSizeId,
        ?int $colorId,
        InventoryMovementDirection $direction,
        int $quantity,
        int $orderId,
    ): void {
        if ($quantity < 1) {
            return;
        }

        $this->inventoryMovementService->recordMovement(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: $direction,
            quantity: $quantity,
            movementType: InventoryMovementType::Purchase,
            referenceType: Order::class,
            referenceId: $orderId,
            createdByUserId: Auth::id(),
        ));
    }
}

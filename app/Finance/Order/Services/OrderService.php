<?php

namespace App\Finance\Order\Services;

use App\Finance\Order\Models\Order;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Services\ModelService;
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
                'notes' => $data['notes'],
            ]);

            foreach ($data['items'] as $item) {
                $productSizeId = $item['product_size_id'];
                $qty = $item['quantity'];

                // 1. Obtener Stock ANTES de la orden (Para el historial)
                $currentStock = 0;
                if ($item['color_id'] > 0) {
                    $colorRow = DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $item['color_id'])
                        ->first();
                    $currentStock = $colorRow ? $colorRow->stock : 0;
                } else {
                    $masterRow = DB::table('product_size')->where('id', $productSizeId)->first();
                    $currentStock = $masterRow ? $masterRow->stock : 0;
                }

                // 2. Aumento de Stock
                $product = Product::findOrFail($item['product_id']);
                $pivotData = [
                    'barcode' => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price' => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                ];
                $this->productSizeService->setStock(
                    $product,
                    $item['size_id'],
                    $qty,
                    $pivotData,
                );

                if ($item['color_id'] > 0) {
                    DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $item['color_id'])
                        ->increment('stock', $qty);
                }

                // 3. Recuperar nombres
                $sizeInfo = DB::table('product_size')
                    ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                    ->join('products', 'product_size.product_id', '=', 'products.id')
                    ->where('product_size.id', $productSizeId)
                    ->select('products.id as pid', 'products.name as pname', 'sizes.description as sname', 'sizes.id as sid')
                    ->first();

                $colorName = $item['color_id'] > 0
                    ? (DB::table('colors')->where('id', $item['color_id'])->value('description') ?? 'Desconocido')
                    : 'Único';

                // 4. Guardar detalle de la orden
                $order->details()->create([
                    'product_id' => $sizeInfo->pid,
                    'size_id' => $sizeInfo->sid,
                    'color_id' => $item['color_id'] > 0 ? $item['color_id'] : null,
                    'product_name_snapshot' => $sizeInfo->pname,
                    'size_name_snapshot' => $sizeInfo->sname,
                    'color_name_snapshot' => $colorName,
                    'quantity' => $qty,
                    'barcode' => $item['barcode'],
                    'purchase_price' => $item['purchase_price'],
                    'sale_price' => $item['sale_price'],
                    'min_sale_price' => $item['min_sale_price'],
                ]);

                // 5. HISTORIAL DETALLADO (Con Stock Antes y Después)
                ProductHistory::create([
                    'product_id' => $sizeInfo->pid,
                    'entity_type' => 'ORDER',
                    'entity_id' => $order->id,
                    'event_type' => 'TAKEN',
                    'old_values' => [
                        'stock' => $currentStock // Stock Antes
                    ],
                    'new_values' => [
                        'stock' => $currentStock - $qty, // Stock Después
                        'quantity' => $qty,
                        'unit_price' => $item['unit_price'],
                        'order_code' => $order->code,
                        'size_name' => $sizeInfo->sname,
                        'color_name' => $colorName
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }
            return $order;
        });
    }
}

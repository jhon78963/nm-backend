<?php

namespace App\Finance\Sale\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail;
use App\Inventory\Product\Models\ProductHistory; // Importante para el historial
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Collection;

class SaleService extends ModelService
{
    public function __construct(Sale $sale)
    {
        parent::__construct($sale);
    }

    /**
     * Reporte de Ventas vs Costos vs Ganancia Agrupado por MES
     */
    public function getMonthlyStats(): Collection
    {
        // 1. INGRESOS: Sacamos directamente de 'sales' sumando 'total_amount'
        $revenues = DB::table('sales')
            ->selectRaw("
                TO_CHAR(creation_time, 'MM-YYYY') as month_year,
                TO_CHAR(creation_time, 'YYYY-MM') as sort_key,
                SUM(total_amount) as total_revenue
            ")
            ->where('is_deleted', false)
            ->where('status', 'COMPLETED')
            ->groupByRaw("TO_CHAR(creation_time, 'MM-YYYY'), TO_CHAR(creation_time, 'YYYY-MM')")
            ->orderBy('sort_key', 'desc')
            ->get()
            ->keyBy('month_year');

        // 2. COSTOS: Calculamos en base al precio de compra del producto
        $costs = DB::table('sales as s')
            ->join('sale_details as sd', 's.id', '=', 'sd.sale_id')
            ->leftJoin('product_size as ps', function ($join) {
                $join->on('sd.product_id', '=', 'ps.product_id')
                    ->on('sd.size_id', '=', 'ps.size_id');
            })
            ->selectRaw("
                TO_CHAR(s.creation_time, 'MM-YYYY') as month_year,
                SUM(sd.quantity * COALESCE(ps.purchase_price, 0)) as total_cost
            ")
            ->where('s.is_deleted', false)
            ->where('s.status', 'COMPLETED')
            ->groupByRaw("TO_CHAR(s.creation_time, 'MM-YYYY')")
            ->pluck('total_cost', 'month_year');

        // 3. FUSIÓN Y CÁLCULO DE GANANCIA
        return $revenues->map(function ($item) use ($costs) {
            $item->total_cost = $costs[$item->month_year] ?? 0;
            $item->profit = $item->total_revenue - $item->total_cost;
            return $item;
        })->values();
    }

    /**
     * Actualiza la venta: Precios, Total y Pagos Mixtos.
     */
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
                        $newPrice = (float) $itemData['unit_price'];
                        $quantity = (int) $detail->quantity;

                        $detail->update([
                            'unit_price' => $newPrice,
                            'subtotal' => $newPrice * $quantity
                        ]);
                    }
                }

                // Recalcular Total General
                $newGrandTotal = $model->details()->sum('subtotal');
                $model->update(['total_amount' => $newGrandTotal]);
            }

            // 3. GESTIÓN DE PAGOS (Edición Mixta)
            if (isset($data['payments']) && is_array($data['payments'])) {
                // Borrar anteriores y crear nuevos para mantener consistencia
                $model->payments()->delete();

                foreach ($data['payments'] as $payment) {
                    $model->payments()->create([
                        'method' => $payment['method'],
                        'amount' => $payment['amount'],
                        'reference' => $payment['reference'] ?? null,
                    ]);
                }

                // Actualizar método principal en cabecera
                $uniqueMethods = collect($data['payments'])->pluck('method')->unique();
                $mainMethod = $uniqueMethods->count() > 1 ? 'MIXTO' : $uniqueMethods->first();
                $model->update(['payment_method' => $mainMethod]);

            } else {
                // Fallback: Si cambió el total pero no enviaron pagos, ajustar si es pago único
                if ($model->payments()->count() === 1) {
                    $model->payments()->first()->update(['amount' => $model->total_amount]);
                }
            }

            return $model->fresh(['details', 'payments']);
        });
    }

    /**
     * Procesa Venta POS (Creación con stock, pagos mixtos e HISTORIAL DETALLADO)
     */
    public function processPosSale(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            // A. Preparar Pagos
            $payments = $data['payments'] ?? [];
            if (empty($payments)) {
                $payments = [['method' => 'CASH', 'amount' => $data['total'], 'reference' => null]];
            }

            $uniqueMethods = collect($payments)->pluck('method')->unique();
            $mainMethod = $uniqueMethods->count() > 1 ? 'MIXTO' : $uniqueMethods->first();

            // B. Crear Cabecera
            $sale = $this->create([
                'customer_id' => $data['customer_id'] ?? null,
                'total_amount' => $data['total'],
                'payment_method' => $mainMethod,
                'status' => 'COMPLETED',
                'code' => 'V-' . time(),
                'creation_time' => now(),
            ]);

            // C. Guardar Pagos
            foreach ($payments as $p) {
                $sale->payments()->create($p);
            }

            // D. Procesar Ítems e Inventario
            foreach ($data['items'] as $item) {
                $productSizeId = $item['product_size_id'];
                $qty = $item['quantity'];

                // 1. Obtener Stock ANTES de la venta (Para el historial)
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

                // 2. Descuentos de Stock
                DB::table('product_size')->where('id', $productSizeId)->decrement('stock', $qty);

                if ($item['color_id'] > 0) {
                    DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $item['color_id'])
                        ->decrement('stock', $qty);
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

                $sale->details()->create([
                    'product_id' => $sizeInfo->pid,
                    'size_id' => $sizeInfo->sid,
                    'color_id' => $item['color_id'] > 0 ? $item['color_id'] : null,
                    'product_name_snapshot' => $sizeInfo->pname,
                    'size_name_snapshot' => $sizeInfo->sname,
                    'color_name_snapshot' => $colorName,
                    'quantity' => $qty,
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['total']
                ]);

                // 4. HISTORIAL DETALLADO (Con Stock Antes y Después)
                ProductHistory::create([
                    'product_id' => $sizeInfo->pid,
                    'entity_type' => 'SALE',
                    'entity_id' => $sale->id,
                    'event_type' => 'TAKEN',
                    'old_values' => [
                        'stock' => $currentStock // Stock Antes
                    ],
                    'new_values' => [
                        'stock' => $currentStock - $qty, // Stock Después
                        'quantity' => $qty,
                        'unit_price' => $item['unit_price'],
                        'sale_code' => $sale->code,
                        'size_name' => $sizeInfo->sname,
                        'color_name' => $colorName
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }
            return $sale;
        });
    }

    /**
     * Procesa Cambio de Mercadería y REGISTRA HISTORIAL CON REFERENCIAS CRUZADAS
     */
    public function processExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            // A. ITEM QUE ENTRA (DEVOLUCIÓN)
            $returnedDetail = SaleDetail::with('sale')->findOrFail($data['returned_detail_id']);
            $originalSale = $returnedDetail->sale;

            // Preparamos datos del nuevo producto (para ponerlo en el log del devuelto)
            $newItemData = $data['new_item'];
            $newPsId = $newItemData['product_size_id'];
            $newColorId = $newItemData['color_id'];

            // Buscar nombres del nuevo producto para el log
            $newProdInfo = DB::table('product_size')
                ->join('products', 'product_size.product_id', '=', 'products.id')
                ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                ->where('product_size.id', $newPsId)
                ->select('products.name as pname', 'sizes.description as sname', 'products.id as pid', 'sizes.id as sid')
                ->first();
            $newColorName = $newColorId > 0 ? DB::table('colors')->where('id', $newColorId)->value('description') : 'Único';
            $newItemFullName = "{$newProdInfo->pname} ({$newProdInfo->sname} | {$newColorName})";


            // 1. Restaurar Stock (Con captura de stock previo)
            $returnedPsId = DB::table('product_size')
                ->where('product_id', $returnedDetail->product_id)
                ->where('size_id', $returnedDetail->size_id)
                ->value('id');

            if ($returnedPsId) {
                // Capturar stock actual antes de incrementar
                $currentStockReturned = 0;
                if ($returnedDetail->color_id) {
                    $cRow = DB::table('product_size_color')
                        ->where('product_size_id', $returnedPsId)
                        ->where('color_id', $returnedDetail->color_id)
                        ->first();
                    $currentStockReturned = $cRow ? $cRow->stock : 0;
                    DB::table('product_size_color')->where('product_size_id', $returnedPsId)->where('color_id', $returnedDetail->color_id)->increment('stock', $returnedDetail->quantity);
                } else {
                    $mRow = DB::table('product_size')->where('id', $returnedPsId)->first();
                    $currentStockReturned = $mRow ? $mRow->stock : 0;
                }
                DB::table('product_size')->where('id', $returnedPsId)->increment('stock', $returnedDetail->quantity);

                // --- HISTORIAL DEVOLUCIÓN ---
                ProductHistory::create([
                    'product_id' => $returnedDetail->product_id,
                    'entity_type' => 'EXCHANGE',
                    'entity_id' => $originalSale->id,
                    'event_type' => 'RETURNED',
                    'old_values' => [
                        'stock' => $currentStockReturned // Stock Antes
                    ],
                    'new_values' => [
                        'stock' => $currentStockReturned + $returnedDetail->quantity,
                        'quantity' => $returnedDetail->quantity,
                        'unit_price' => $returnedDetail->unit_price,
                        'sale_code' => $originalSale->code,
                        'size_name' => $returnedDetail->size_name_snapshot,
                        'color_name' => $returnedDetail->color_name_snapshot,
                        'exchange_note' => "Cambiado por: $newItemFullName"
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }

            // B. ITEM QUE SALE (NUEVO)
            // PRIORIDAD: Precio final negociado
            $newPrice = isset($newItemData['final_price'])
                ? (float) $newItemData['final_price']
                : (DB::table('product_size')->where('id', $newPsId)->value('sale_price') ?? 0);

            $qty = 1;

            // 2. Descontar Stock Nuevo (Con captura)
            $masterInventory = DB::table('product_size')->where('id', $newPsId)->lockForUpdate()->first();
            if (!$masterInventory)
                throw new Exception("Talla nueva no encontrada.");

            // Capturar stock actual antes de restar
            $currentStockNew = 0;
            if ($newColorId > 0) {
                $cRowNew = DB::table('product_size_color')->where('product_size_id', $newPsId)->where('color_id', $newColorId)->first();
                $currentStockNew = $cRowNew ? $cRowNew->stock : 0;
                DB::table('product_size_color')->where('product_size_id', $newPsId)->where('color_id', $newColorId)->decrement('stock', $qty);
            } else {
                $currentStockNew = $masterInventory->stock;
            }
            DB::table('product_size')->where('id', $newPsId)->decrement('stock', $qty);

            // 3. Crear Detalle Nuevo
            $originalSale->details()->create([
                'product_id' => $newProdInfo->pid,
                'size_id' => $newProdInfo->sid,
                'color_id' => $newColorId > 0 ? $newColorId : null,
                'product_name_snapshot' => $newProdInfo->pname,
                'size_name_snapshot' => $newProdInfo->sname,
                'color_name_snapshot' => $newColorName,
                'quantity' => $qty,
                'unit_price' => $newPrice,
                'subtotal' => $newPrice * $qty
            ]);

            // --- HISTORIAL SALIDA NUEVO ---
            $returnedFullName = "{$returnedDetail->product_name_snapshot} ({$returnedDetail->size_name_snapshot} | {$returnedDetail->color_name_snapshot})";

            ProductHistory::create([
                'product_id' => $newProdInfo->pid,
                'entity_type' => 'EXCHANGE',
                'entity_id' => $originalSale->id,
                'event_type' => 'TAKEN',
                'old_values' => [
                    'stock' => $currentStockNew // Stock Antes
                ],
                'new_values' => [
                    'stock' => $currentStockNew - $qty, // Stock Después
                    'quantity' => $qty,
                    'unit_price' => $newPrice,
                    'sale_code' => $originalSale->code,
                    'size_name' => $newProdInfo->sname,
                    'color_name' => $newColorName,
                    'exchange_note' => "Cambio desde: $returnedFullName"
                ],
                'creator_user_id' => Auth::id(),
            ]);

            // 4. Eliminar Detalle Antiguo
            $returnedDetail->delete();

            // 5. Actualizar Total Venta
            $newTotal = $originalSale->details()->sum('subtotal');
            $originalSale->update(['total_amount' => $newTotal]);

            // 6. ACTUALIZAR PAGOS (SANEAMIENTO)
            $originalSale->payments()->delete();
            $newPaymentMethod = $data['payment_method'] ?? 'CASH';

            $originalSale->payments()->create([
                'method' => $newPaymentMethod,
                'amount' => $newTotal,
                'reference' => 'CAMBIO MERCADERÍA',
                'created_at' => now()
            ]);
            $originalSale->update(['payment_method' => $newPaymentMethod]);

            // 7. Caja Chica
            $difference = $data['difference_amount'];
            if ($difference > 0) {
                CashMovement::create([
                    'type' => 'INCOME',
                    'amount' => $difference,
                    'description' => "Cambio Mercadería #{$originalSale->code} (Diferencia)",
                    'payment_method' => $data['payment_method'] ?? 'CASH',
                    'creation_time' => now(),
                    'creator_user_id' => auth()->id()
                ]);
            }

            // 8. Notas
            $note = "CAMBIO: Entregó {$returnedDetail->product_name_snapshot}, Llevó {$newProdInfo->pname} (S/ {$newPrice}).";
            $originalSale->notes = substr(($originalSale->notes ?? '') . " | " . $note, -250);
            $originalSale->save();

            return true;
        });
    }
}

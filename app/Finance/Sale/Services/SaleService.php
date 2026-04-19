<?php

namespace App\Finance\Sale\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail;
use App\Inventory\Product\Models\ProductHistory;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Collection;
use stdClass;

class SaleService extends ModelService
{
    public function __construct(Sale $sale)
    {
        parent::__construct($sale);
    }

    /**
     * Reporte de Ventas vs Costos vs Ganancia agrupado por mes (una sola pasada a BD).
     */
    public function getMonthlyStats(): Collection
    {
        $rows = DB::select("
            WITH rev AS (
                SELECT TO_CHAR(creation_time, 'MM-YYYY') AS month_year,
                       TO_CHAR(creation_time, 'YYYY-MM') AS sort_key,
                       SUM(total_amount) AS total_revenue
                FROM sales
                WHERE is_deleted = false AND status = 'COMPLETED'
                GROUP BY 1, 2
            ),
            cst AS (
                SELECT TO_CHAR(s.creation_time, 'MM-YYYY') AS month_year,
                       SUM(sd.quantity * COALESCE(ps.purchase_price, 0)) AS total_cost
                FROM sales s
                INNER JOIN sale_details sd ON s.id = sd.sale_id
                LEFT JOIN product_size ps
                    ON sd.product_id = ps.product_id AND sd.size_id = ps.size_id
                WHERE s.is_deleted = false AND s.status = 'COMPLETED'
                GROUP BY 1
            )
            SELECT r.month_year,
                   r.sort_key,
                   r.total_revenue,
                   COALESCE(c.total_cost, 0) AS total_cost,
                   r.total_revenue - COALESCE(c.total_cost, 0) AS profit
            FROM rev r
            LEFT JOIN cst c ON c.month_year = r.month_year
            ORDER BY r.sort_key DESC
        ");

        return collect($rows)->map(function (stdClass $item) {
            $item->total_revenue = (float) $item->total_revenue;
            $item->total_cost = (float) $item->total_cost;
            $item->profit = (float) $item->profit;

            return $item;
        });
    }

    /**
     * Actualiza la venta: Precios, Total y Pagos Mixtos.
     */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {
            // 1. Actualizar cabecera (notas, cliente, etc.)
            $model->fill($data);
            $model->save();

            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $detail = $model->details()->where('id', $itemData['id'])->first();
                    if (!$detail)
                        continue;

                    $oldQty = (int) $detail->quantity;
                    $newQty = (int) $itemData['quantity'];
                    $newPrice = (float) $itemData['unit_price'];

                    // Obtener ID de inventario actual
                    $oldPsId = DB::table('product_size')
                        ->where('product_id', $detail->product_id)
                        ->where('size_id', $detail->size_id)
                        ->value('id');

                    // Detectar si el Front mandó una nueva Talla/Producto
                    $hasNewPsId = isset($itemData['product_size_id']);
                    $newPsId = $hasNewPsId ? $itemData['product_size_id'] : $oldPsId;

                    // Manejo de color (si no viene, mantenemos el anterior)
                    $newColorId = array_key_exists('color_id', $itemData)
                        ? ($itemData['color_id'] > 0 ? $itemData['color_id'] : null)
                        : $detail->color_id;

                    // DETERMINAR SI ES CAMBIO DE PRODUCTO
                    $isExchange = ($newPsId != $oldPsId) || ($hasNewPsId && $newColorId != $detail->color_id);

                    if ($isExchange) {
                        // ESCENARIO A: CAMBIO DE PRODUCTO (Devolver stock viejo / Quitar nuevo)
                        $this->adjustStock($oldPsId, $detail->color_id, $oldQty, 'increment');
                        $this->adjustStock($newPsId, $newColorId, $newQty, 'decrement');

                        // Generar nuevos nombres/fotos para el detalle
                        $snap = $this->getNewProductSnapshot($newPsId, $newColorId);
                        $detail->fill($snap); // Carga los campos _snapshot
                        $detail->color_id = $newColorId;
                    } else {
                        // ESCENARIO B: MISMO PRODUCTO, SOLO CANTIDAD
                        $diff = $newQty - $oldQty;
                        if ($diff !== 0) {
                            $this->adjustStock($oldPsId, $detail->color_id, $diff, 'decrement');
                        }
                    }

                    // --- ASIGNACIÓN FINAL ---
                    // Seteamos los valores directamente en el modelo
                    $detail->quantity = $newQty;
                    $detail->unit_price = $newPrice;
                    $detail->subtotal = $newQty * $newPrice;

                    // save() persiste TODO lo que el modelo tenga modificado (fill + asignaciones)
                    $detail->save();
                }

                // Recalcular Total General de la Venta
                $model->update(['total_amount' => $model->details()->sum('subtotal')]);
            }

            // 3. GESTIÓN DE PAGOS (Borrar y Recrear)
            if (isset($data['payments']) && is_array($data['payments'])) {
                $model->payments()->delete();
                foreach ($data['payments'] as $p) {
                    $model->payments()->create([
                        'method' => $p['method'],
                        'amount' => $p['amount'],
                        'reference' => $p['reference'] ?? 'AJUSTE EN EDICIÓN',
                    ]);
                }
                $uniqueMethods = collect($data['payments'])->pluck('method')->unique();
                $model->update(['payment_method' => $uniqueMethods->count() > 1 ? 'MIXTO' : $uniqueMethods->first()]);
            }

            return $model->fresh(['details', 'payments']);
        });
    }

    /**
     * Helper para no repetir lógica de incremento/decremento de stock
     */
    private function adjustStock($psId, $colorId, $qty, $method)
    {
        if (!$psId || $qty == 0)
            return;

        DB::table('product_size')->where('id', $psId)->$method('stock', $qty);

        if ($colorId) {
            DB::table('product_size_color')
                        ->where('product_size_id', $psId)
                        ->where('color_id', $colorId)
                ->$method('stock', $qty);
        }
    }

    /**
     * Obtiene nombres para el snapshot al cambiar producto
     */
    private function getNewProductSnapshot($psId, $colorId)
    {
        $data = DB::table('product_size as ps')
            ->join('products as p', 'ps.product_id', '=', 'p.id')
            ->join('sizes as s', 'ps.size_id', '=', 's.id')
            ->where('ps.id', $psId)
            ->select('p.id as product_id', 's.id as size_id', 'p.name as product_name', 's.description as size_name')
            ->first();

        $colorName = $colorId ? DB::table('colors')->where('id', $colorId)->value('description') : 'Único';

        return [
            'product_id' => $data->product_id,
            'size_id' => $data->size_id,
            'product_name_snapshot' => $data->product_name,
            'size_name_snapshot' => $data->size_name,
            'color_name_snapshot' => $colorName
        ];
    }

    /**
     * Anula una venta, devuelve el stock y registra el historial.
     */
    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model) {
            // 1. VALIDACIÓN ANTIDUPLE (Vital)
            if ($model->is_deleted || $model->status === 'CANCELED') {
                throw new Exception("La venta {$model->code} ya se encuentra anulada.");
            }

            $model->load('details');

            foreach ($model->details as $detail) {
                // Buscamos el ID de la relación maestra
                $psId = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->value('id');

                if ($psId) {
                    $currentStock = 0;

                    // 2. Bloqueamos las filas de stock para que el historial sea exacto
                    if ($detail->color_id) {
                        $colorRow = DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->lockForUpdate() // <--- Bloqueo de fila
                            ->first();

                        $currentStock = $colorRow ? $colorRow->stock : 0;

                        DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->increment('stock', $detail->quantity);
                    } else {
                        $masterRow = DB::table('product_size')
                            ->where('id', $psId)
                            ->lockForUpdate()
                            ->first();
                        $currentStock = $masterRow ? $masterRow->stock : 0;
                    }

                    // Devolver stock a la tabla maestra
                    DB::table('product_size')->where('id', $psId)->increment('stock', $detail->quantity);

                    // 3. Registrar en Historial (Con valores reales bloqueados)
                    ProductHistory::create([
                        'product_id' => $detail->product_id,
                        'entity_type' => 'SALE_VOIDED',
                        'entity_id' => $model->id,
                        'event_type' => 'RETURNED',
                        'old_values' => ['stock' => $currentStock],
                        'new_values' => [
                            'stock' => $currentStock + $detail->quantity,
                            'quantity' => $detail->quantity,
                            'reason' => 'Venta anulada por el usuario',
                            'sale_code' => $model->code
                        ],
                        'creator_user_id' => Auth::id(),
                    ]);
                }
            }

            // 4. Marcar como eliminada
            $model->update([
                'is_deleted' => true,
                'status' => 'CANCELED',
                'notes' => substr(($model->notes ?? '') . " | ANULADA EL " . now()->format('d/m/Y H:i'), -250)
            ]);
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

            // D. Procesar Ítems e Inventario (bloqueo de filas para evitar sobreventa concurrente)
            foreach ($data['items'] as $item) {
                $productSizeId = $item['product_size_id'];
                $qty = $item['quantity'];
                $colorId = (int) $item['color_id'];

                $masterRow = DB::table('product_size')
                    ->where('id', $productSizeId)
                    ->lockForUpdate()
                    ->first();
                if (!$masterRow) {
                    throw new Exception('Talla de producto no encontrada.');
                }

                $currentStock = 0;
                if ($colorId > 0) {
                    $colorRow = DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->lockForUpdate()
                        ->first();
                    $currentStock = $colorRow ? (int) $colorRow->stock : 0;
                    if ($currentStock < $qty) {
                        throw new Exception('Stock insuficiente para el color seleccionado.');
                    }
                } else {
                    $currentStock = (int) $masterRow->stock;
                    if ($currentStock < $qty) {
                        throw new Exception('Stock insuficiente para el producto.');
                    }
                }

                DB::table('product_size')->where('id', $productSizeId)->decrement('stock', $qty);

                if ($colorId > 0) {
                    DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->decrement('stock', $qty);
                }

                // 3. Recuperar nombres
                $sizeInfo = DB::table('product_size')
                    ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                    ->join('products', 'product_size.product_id', '=', 'products.id')
                    ->where('product_size.id', $productSizeId)
                    ->select('products.id as pid', 'products.name as pname', 'sizes.description as sname', 'sizes.id as sid')
                    ->first();

                if (!$sizeInfo) {
                    throw new Exception('No se pudo resolver el producto para el ítem.');
                }

                $colorName = $colorId > 0
                    ? (DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido')
                    : 'Único';

                $sale->details()->create([
                    'product_id' => $sizeInfo->pid,
                    'size_id' => $sizeInfo->sid,
                    'color_id' => $colorId > 0 ? $colorId : null,
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

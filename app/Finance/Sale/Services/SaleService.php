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
                $items = $this->sortUpdateItemsByProductSizeId($model, $data['items']);
                foreach ($items as $itemData) {
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
                        $this->ensureProductSizeMastersLocked(
                            $oldPsId ? (int) $oldPsId : null,
                            $newPsId ? (int) $newPsId : null,
                        );
                        if ($oldPsId) {
                            $this->applyStockDeltaLocked(
                                (int) $oldPsId,
                                $detail->color_id,
                                $oldQty,
                                true,
                                (int) $model->id,
                                (string) ($model->code ?? ''),
                                (int) $detail->id,
                            );
                        }
                        if ($newPsId) {
                            $this->applyStockDeltaLocked(
                                (int) $newPsId,
                                $newColorId,
                                $newQty,
                                false,
                                (int) $model->id,
                                (string) ($model->code ?? ''),
                                (int) $detail->id,
                            );
                        }

                        // Generar nuevos nombres/fotos para el detalle
                        $snap = $this->getNewProductSnapshot($newPsId, $newColorId);
                        $detail->fill($snap); // Carga los campos _snapshot
                        $detail->color_id = $newColorId;
                    } else {
                        // ESCENARIO B: MISMO PRODUCTO, SOLO CANTIDAD
                        $diff = $newQty - $oldQty;
                        if ($diff !== 0 && $oldPsId) {
                            $this->ensureProductSizeMastersLocked((int) $oldPsId);
                            if ($diff > 0) {
                                $this->applyStockDeltaLocked(
                                    (int) $oldPsId,
                                    $detail->color_id,
                                    $diff,
                                    false,
                                    (int) $model->id,
                                    (string) ($model->code ?? ''),
                                    (int) $detail->id,
                                );
                            } else {
                                $this->applyStockDeltaLocked(
                                    (int) $oldPsId,
                                    $detail->color_id,
                                    abs($diff),
                                    true,
                                    (int) $model->id,
                                    (string) ($model->code ?? ''),
                                    (int) $detail->id,
                                );
                            }
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
     * Anti-deadlock: orden ascendente únicamente por `product_size_id` estable del ítem
     * (valor enviado por el cliente o el maestro actual del detalle).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortUpdateItemsByProductSizeId(Model $model, array $items): array
    {
        $items = array_values($items);
        usort(
            $items,
            fn (array $a, array $b): int => $this->resolveSaleUpdateItemSortKeyProductSizeId($model, $a)
                <=> $this->resolveSaleUpdateItemSortKeyProductSizeId($model, $b),
        );

        return $items;
    }

    /**
     * Clave estable: payload `product_size_id` (> 0); si no, `product_size.id` del par producto+talla vigente del detalle.
     */
    private function resolveSaleUpdateItemSortKeyProductSizeId(Model $model, array $itemData): int
    {
        $detail = $model->details()->where('id', $itemData['id'] ?? 0)->first();
        if (! $detail) {
            return PHP_INT_MAX;
        }

        if (isset($itemData['product_size_id'])) {
            $fromPayload = (int) $itemData['product_size_id'];
            if ($fromPayload > 0) {
                return $fromPayload;
            }
        }

        $currentPsId = DB::table('product_size')
            ->where('product_id', $detail->product_id)
            ->where('size_id', $detail->size_id)
            ->value('id');

        return $currentPsId !== null ? (int) $currentPsId : PHP_INT_MAX;
    }

    /**
     * Bloquea filas maestras product_size en orden determinístico (evita deadlocks entre transacciones).
     *
     * @param  int|null  ...$psIds  IDs en product_size (null o ≤0 se ignoran)
     */
    private function ensureProductSizeMastersLocked(?int ...$psIds): void
    {
        $ids = [];
        foreach ($psIds as $id) {
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        foreach ($ids as $id) {
            $row = DB::table('product_size')->where('id', $id)->lockForUpdate()->first();
            if (!$row) {
                throw new Exception('Talla de producto no encontrada.');
            }
        }
    }

    /**
     * Exige que la fila maestra correspondiente ya esté bloqueada vía ensureProductSizeMastersLocked().
     * Revalida con lockForUpdate en maestro y pivote (color); registra ProductHistory (SALE_UPDATE) según lecturas bajo lock.
     */
    private function applyStockDeltaLocked(
        int $psId,
        ?int $colorId,
        int $qty,
        bool $increment,
        int $saleId,
        string $saleCode,
        int $saleDetailId,
    ): void {
        if ($qty === 0 || $psId <= 0) {
            return;
        }

        $masterRow = DB::table('product_size')->where('id', $psId)->lockForUpdate()->first();
        if (! $masterRow) {
            throw new Exception('Talla de producto no encontrada.');
        }

        $productId = (int) $masterRow->product_id;
        $effectiveColorId = $colorId ? (int) $colorId : null;

        $colorPivotStock = 0;
        if ($effectiveColorId) {
            $colorRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $effectiveColorId)
                ->lockForUpdate()
                ->first();

            $colorPivotStock = $colorRow ? (int) $colorRow->stock : 0;

            if (! $increment && $colorPivotStock < $qty) {
                throw new Exception('Stock insuficiente para el color seleccionado.');
            }
        }

        $masterStock = (int) $masterRow->stock;
        if (! $increment && $masterStock < $qty) {
            throw new Exception('Stock insuficiente para el producto.');
        }

        $oldStockAudited = $effectiveColorId ? $colorPivotStock : $masterStock;

        if ($increment) {
            DB::table('product_size')->where('id', $psId)->increment('stock', $qty);

            if ($effectiveColorId) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $effectiveColorId)
                    ->increment('stock', $qty);
            }
        } else {
            DB::table('product_size')->where('id', $psId)->decrement('stock', $qty);

            if ($effectiveColorId) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $effectiveColorId)
                    ->decrement('stock', $qty);
            }
        }

        $newStockAudited = $effectiveColorId
            ? (int) (DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $effectiveColorId)
                ->lockForUpdate()
                ->value('stock') ?? 0)
            : (int) (DB::table('product_size')->where('id', $psId)->lockForUpdate()->value('stock') ?? 0);

        ProductHistory::create([
            'product_id' => $productId,
            'entity_type' => 'SALE_UPDATE',
            'entity_id' => $saleId,
            'event_type' => $increment ? 'RETURNED' : 'TAKEN',
            'old_values' => [
                'stock' => $oldStockAudited,
            ],
            'new_values' => [
                'stock' => $newStockAudited,
                'quantity' => $qty,
                'sale_code' => $saleCode,
                'sale_detail_id' => $saleDetailId,
                'product_size_id' => $psId,
                'color_id' => $effectiveColorId,
            ],
            'creator_user_id' => Auth::id(),
        ]);
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
                // Buscamos el ID de la relación maestra
                $psId = DB::table('product_size')
                    ->where('product_id', $detail->product_id)
                    ->where('size_id', $detail->size_id)
                    ->value('id');

                if ($psId) {
                    $currentStock = 0;

                    // Orden jerárquico: maestro primero, pivot color después (alineado con ventas/compras).
                    $masterRow = DB::table('product_size')
                        ->where('id', $psId)
                        ->lockForUpdate()
                        ->first();

                    if (!$masterRow) {
                        continue;
                    }

                    if ($detail->color_id) {
                        $colorRow = DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->lockForUpdate()
                            ->first();

                        $currentStock = $colorRow ? $colorRow->stock : 0;

                        DB::table('product_size')->where('id', $psId)->increment('stock', $detail->quantity);

                        DB::table('product_size_color')
                            ->where('product_size_id', $psId)
                            ->where('color_id', $detail->color_id)
                            ->increment('stock', $detail->quantity);
                    } else {
                        $currentStock = (int) $masterRow->stock;

                        DB::table('product_size')->where('id', $psId)->increment('stock', $detail->quantity);
                    }

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
            // A. Total de venta solo desde cantidad × precio unitario (no confiar en totales del cliente)
            $computedTotal = 0.0;
            $lines = [];
            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $lineSubtotal = round($qty * $unitPrice, 2);
                $computedTotal = round($computedTotal + $lineSubtotal, 2);
                $lines[] = array_merge($item, ['line_subtotal' => $lineSubtotal]);
            }

            // B. Pagos deben cuadrar con el total calculado en backend
            $payments = $data['payments'] ?? [];
            if ($payments === []) {
                $payments = [['method' => 'CASH', 'amount' => $computedTotal, 'reference' => null]];
            }

            $paymentsSum = 0.0;
            foreach ($payments as $p) {
                $paymentsSum = round($paymentsSum + (float) ($p['amount'] ?? 0), 2);
            }

            if (abs($paymentsSum - $computedTotal) > 0.02) {
                throw new Exception(
                    'La suma de los pagos no coincide con el total calculado de la venta.'
                );
            }

            $uniqueMethods = collect($payments)->pluck('method')->unique();
            $mainMethod = $uniqueMethods->count() > 1 ? 'MIXTO' : $uniqueMethods->first();

            // C. Crear Cabecera
            $sale = $this->create([
                'customer_id' => $data['customer_id'] ?? null,
                'total_amount' => $computedTotal,
                'payment_method' => $mainMethod,
                'status' => 'COMPLETED',
                'code' => 'V-' . time(),
                'creation_time' => now(),
            ]);

            // D. Guardar Pagos
            foreach ($payments as $p) {
                $sale->payments()->create($p);
            }

            usort(
                $lines,
                fn (array $a, array $b): int => ((int) ($a['product_size_id'] ?? 0)) <=> ((int) ($b['product_size_id'] ?? 0)),
            );

            // E. Procesar Ítems e Inventario (bloqueo de filas para evitar sobreventa concurrente)
            foreach ($lines as $item) {
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
                    'subtotal' => $item['line_subtotal'],
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

            $newItemData = $data['new_item'];
            $newPsId = (int) $newItemData['product_size_id'];
            $newColorId = (int) $newItemData['color_id'];
            $qty = (int) $returnedDetail->quantity;

            $returnedPsId = DB::table('product_size')
                ->where('product_id', $returnedDetail->product_id)
                ->where('size_id', $returnedDetail->size_id)
                ->value('id');

            $this->ensureProductSizeMastersLocked(
                $returnedPsId ? (int) $returnedPsId : null,
                $newPsId,
            );

            $newProdInfo = DB::table('product_size')
                ->join('products', 'product_size.product_id', '=', 'products.id')
                ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                ->where('product_size.id', $newPsId)
                ->select('products.name as pname', 'sizes.description as sname', 'products.id as pid', 'sizes.id as sid')
                ->first();

            if (!$newProdInfo) {
                throw new Exception('Talla nueva no encontrada.');
            }

            $newColorName = $newColorId > 0
                ? (DB::table('colors')->where('id', $newColorId)->value('description') ?? 'Único')
                : 'Único';
            $newItemFullName = "{$newProdInfo->pname} ({$newProdInfo->sname} | {$newColorName})";

            // 1. Restaurar Stock (maestros ya bloqueados; bloqueo explícito en color si aplica)
            if ($returnedPsId) {
                $returnedPsId = (int) $returnedPsId;

                $currentStockReturned = 0;
                if ($returnedDetail->color_id) {
                    $cRow = DB::table('product_size_color')
                        ->where('product_size_id', $returnedPsId)
                        ->where('color_id', $returnedDetail->color_id)
                        ->lockForUpdate()
                        ->first();
                    $currentStockReturned = $cRow ? (int) $cRow->stock : 0;

                    DB::table('product_size')->where('id', $returnedPsId)->increment('stock', $qty);

                    DB::table('product_size_color')
                        ->where('product_size_id', $returnedPsId)
                        ->where('color_id', $returnedDetail->color_id)
                        ->increment('stock', $qty);
                } else {
                    $mRow = DB::table('product_size')->where('id', $returnedPsId)->first();
                    $currentStockReturned = $mRow ? (int) $mRow->stock : 0;

                    DB::table('product_size')->where('id', $returnedPsId)->increment('stock', $qty);
                }

                ProductHistory::create([
                    'product_id' => $returnedDetail->product_id,
                    'entity_type' => 'EXCHANGE',
                    'entity_id' => $originalSale->id,
                    'event_type' => 'RETURNED',
                    'old_values' => [
                        'stock' => $currentStockReturned,
                    ],
                    'new_values' => [
                        'stock' => $currentStockReturned + $qty,
                        'quantity' => $qty,
                        'unit_price' => $returnedDetail->unit_price,
                        'sale_code' => $originalSale->code,
                        'size_name' => $returnedDetail->size_name_snapshot,
                        'color_name' => $returnedDetail->color_name_snapshot,
                        'exchange_note' => "Cambiado por: $newItemFullName",
                    ],
                    'creator_user_id' => Auth::id(),
                ]);
            }

            // B. ITEM QUE SALE (NUEVO): lecturas tras devolución por si comparte product_size con el devuelto
            $masterInventory = DB::table('product_size')->where('id', $newPsId)->first();
            if (!$masterInventory) {
                throw new Exception('Talla nueva no encontrada.');
            }

            $newPrice = isset($newItemData['final_price'])
                ? (float) $newItemData['final_price']
                : (float) ($masterInventory->sale_price ?? 0);

            $currentStockNew = 0;
            if ($newColorId > 0) {
                $cRowNew = DB::table('product_size_color')
                    ->where('product_size_id', $newPsId)
                    ->where('color_id', $newColorId)
                    ->lockForUpdate()
                    ->first();
                $currentStockNew = $cRowNew ? (int) $cRowNew->stock : 0;

                if ($currentStockNew < $qty) {
                    throw new Exception('Stock insuficiente para el color seleccionado.');
                }

                DB::table('product_size')->where('id', $newPsId)->decrement('stock', $qty);

                DB::table('product_size_color')
                    ->where('product_size_id', $newPsId)
                    ->where('color_id', $newColorId)
                    ->decrement('stock', $qty);
            } else {
                $currentStockNew = (int) $masterInventory->stock;
                if ($currentStockNew < $qty) {
                    throw new Exception('Stock insuficiente para el producto.');
                }

                DB::table('product_size')->where('id', $newPsId)->decrement('stock', $qty);
            }

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

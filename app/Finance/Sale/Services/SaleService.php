<?php

namespace App\Finance\Sale\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail;
use App\Inventory\Support\InventoryManager;
use App\Shared\Foundation\Services\ModelService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaleService extends ModelService
{
    public function __construct(
        Sale $sale,
        protected InventoryManager $inventory,
    ) {
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

        return collect($rows)->map(function ($item) {
            $item->total_revenue = (float) $item->total_revenue;
            $item->total_cost    = (float) $item->total_cost;
            $item->profit        = (float) $item->profit;

            return $item;
        });
    }

    /**
     * Actualiza la venta: precios, cantidades, cambios de producto/color y pagos mixtos.
     *
     * LÓGICA DE ISEXCHANGE:
     * - Se usa array_key_exists() en lugar de isset() para distinguir correctamente
     *   entre "el frontend no mandó el campo" y "lo mandó explícitamente como null".
     * - Un cambio de color sin cambio de talla también cuenta como exchange, por eso
     *   se evalúan ambas condiciones de forma independiente.
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

                    $oldQty   = (int) $detail->quantity;
                    $newQty   = (int) $itemData['quantity'];
                    $newPrice = (float) $itemData['unit_price'];

                    // ID del product_size actual del detalle
                    $oldPsId = $this->inventory->resolveProductSizeId(
                        $detail->product_id,
                        $detail->size_id
                    );

                    // ── Determinar nuevo product_size_id ────────────────────────────────
                    // array_key_exists detecta la clave aunque su valor sea null,
                    // evitando el bug en que isset() devuelve false para null.
                    $hasNewPsId = array_key_exists('product_size_id', $itemData)
                        && $itemData['product_size_id'] !== null;
                    $newPsId = $hasNewPsId ? (int) $itemData['product_size_id'] : $oldPsId;

                    // ── Determinar nuevo color_id ────────────────────────────────────────
                    $newColorId = array_key_exists('color_id', $itemData)
                        ? ($itemData['color_id'] > 0 ? (int) $itemData['color_id'] : null)
                        : $detail->color_id;

                    $productChanged = ($newPsId !== $oldPsId);
                    $colorChanged   = ((int) ($newColorId ?? 0)) !== ((int) ($detail->color_id ?? 0));
                    $isExchange     = $productChanged || $colorChanged;

                    if ($isExchange) {
                        // ESCENARIO A: cambio de producto O de color
                        // Devolvemos el stock del ítem anterior y descontamos el nuevo.
                        $this->inventory->increment($oldPsId, $detail->color_id, $oldQty);
                        $this->inventory->decrement($newPsId, $newColorId, $newQty);

                        $snap = $this->getNewProductSnapshot($newPsId, $newColorId);
                        $detail->fill($snap);
                        $detail->color_id = $newColorId;
                    } else {
                        // ESCENARIO B: mismo producto y color, solo cambia cantidad
                        $diff = $newQty - $oldQty;
                        if ($diff > 0) {
                            $this->inventory->decrement($oldPsId, $detail->color_id, $diff);
                        } elseif ($diff < 0) {
                            $this->inventory->increment($oldPsId, $detail->color_id, abs($diff));
                        }
                    }

                    $detail->quantity   = $newQty;
                    $detail->unit_price = $newPrice;
                    $detail->subtotal   = $newQty * $newPrice;
                    $detail->save();
                }

                $model->update(['total_amount' => $model->details()->sum('subtotal')]);
            }

            // Gestión de pagos: borrar y recrear
            if (isset($data['payments']) && is_array($data['payments'])) {
                $model->payments()->delete();
                foreach ($data['payments'] as $p) {
                    $model->payments()->create([
                        'method'    => $p['method'],
                        'amount'    => $p['amount'],
                        'reference' => $p['reference'] ?? 'AJUSTE EN EDICIÓN',
                    ]);
                }
                $uniqueMethods = collect($data['payments'])->pluck('method')->unique();
                $model->update([
                    'payment_method' => $uniqueMethods->count() > 1
                        ? 'MIXTO'
                        : $uniqueMethods->first(),
                ]);
            }

            return $model->fresh(['details', 'payments']);
        });
    }

    /**
     * Anula la venta, devuelve el stock a inventario y registra el historial.
     *
     * CORRECCIÓN DE LOCKING: la fila maestra se bloquea siempre (no solo cuando
     * no hay color), ya que el increment() al final siempre afecta product_size.
     * InventoryManager encapsula ambos bloqueos de forma atómica.
     */
    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model) {
            if ($model->is_deleted || $model->status === 'CANCELED') {
                throw new Exception("La venta {$model->code} ya se encuentra anulada.");
            }

            $model->load('details');

            foreach ($model->details as $detail) {
                $psId = $this->inventory->resolveProductSizeId(
                    $detail->product_id,
                    $detail->size_id
                );

                if (!$psId) {
                    continue;
                }

                // increment() adquiere lockForUpdate sobre master Y color,
                // y retorna el stock ANTES de incrementar para el historial.
                $stockBefore = $this->inventory->increment(
                    $psId,
                    $detail->color_id,
                    $detail->quantity
                );

                $this->inventory->recordHistory(
                    productId:   $detail->product_id,
                    entityType:  'SALE_VOIDED',
                    entityId:    $model->id,
                    eventType:   'RETURNED',
                    stockBefore: $stockBefore,
                    stockAfter:  $stockBefore + $detail->quantity,
                    extra: [
                        'quantity'  => $detail->quantity,
                        'reason'    => 'Venta anulada por el usuario',
                        'sale_code' => $model->code,
                    ]
                );
            }

            $model->update([
                'is_deleted' => true,
                'status'     => 'CANCELED',
                'notes'      => substr(
                    ($model->notes ?? '') . ' | ANULADA EL ' . now()->format('d/m/Y H:i'),
                    -250
                ),
            ]);
        });
    }

    /**
     * Procesa Venta POS con stock, pagos mixtos e historial detallado.
     */
    public function processPosSale(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $payments = $data['payments'] ?? [];
            if (empty($payments)) {
                $payments = [['method' => 'CASH', 'amount' => $data['total'], 'reference' => null]];
            }

            $uniqueMethods = collect($payments)->pluck('method')->unique();
            $mainMethod    = $uniqueMethods->count() > 1 ? 'MIXTO' : $uniqueMethods->first();

            $sale = $this->create([
                'customer_id'    => $data['customer_id'] ?? null,
                'total_amount'   => $data['total'],
                'warehouse_id'   => $data['warehouse_id'] ?? null,
                'payment_method' => $mainMethod,
                'status'         => 'COMPLETED',
                'code'           => 'V-' . time(),
                'creation_time'  => now(),
            ]);

            foreach ($payments as $p) {
                $sale->payments()->create($p);
            }

            foreach ($data['items'] as $item) {
                $productSizeId = (int) $item['product_size_id'];
                $qty           = (int) $item['quantity'];
                $colorId       = (int) ($item['color_id'] ?? 0) > 0 ? (int) $item['color_id'] : null;

                // decrement() bloquea, valida y retorna el stock antes de la operación.
                $stockBefore = $this->inventory->decrement($productSizeId, $colorId, $qty);

                $sizeInfo = DB::table('product_size')
                    ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                    ->join('products', 'product_size.product_id', '=', 'products.id')
                    ->where('product_size.id', $productSizeId)
                    ->select(
                        'products.id as pid',
                        'products.name as pname',
                        'sizes.description as sname',
                        'sizes.id as sid'
                    )
                    ->first();

                if (!$sizeInfo) {
                    throw new Exception('No se pudo resolver el producto para el ítem.');
                }

                $colorName = $colorId
                    ? (DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido')
                    : 'Único';

                $sale->details()->create([
                    'product_id'             => $sizeInfo->pid,
                    'size_id'                => $sizeInfo->sid,
                    'color_id'               => $colorId,
                    'product_name_snapshot'  => $sizeInfo->pname,
                    'size_name_snapshot'     => $sizeInfo->sname,
                    'color_name_snapshot'    => $colorName,
                    'quantity'               => $qty,
                    'unit_price'             => $item['unit_price'],
                    'subtotal'               => $item['total'],
                ]);

                $this->inventory->recordHistory(
                    productId:   $sizeInfo->pid,
                    entityType:  'SALE',
                    entityId:    $sale->id,
                    eventType:   'TAKEN',
                    stockBefore: $stockBefore,
                    stockAfter:  $stockBefore - $qty,
                    extra: [
                        'quantity'   => $qty,
                        'unit_price' => $item['unit_price'],
                        'sale_code'  => $sale->code,
                        'size_name'  => $sizeInfo->sname,
                        'color_name' => $colorName,
                    ]
                );
            }

            return $sale;
        });
    }

    /**
     * Procesa Cambio de Mercadería con historial cruzado.
     *
     * CORRECCIONES:
     * - $qty ya no está hardcodeado a 1; usa la cantidad del detalle devuelto como
     *   referencia natural de un cambio 1-a-1, admitiendo sobreescritura por payload.
     * - Se valida stock del nuevo producto ANTES de cualquier escritura.
     * - Los bloqueos de la devolución también pasan por InventoryManager.
     */
    public function processExchange(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            $returnedDetail = SaleDetail::with('sale')->findOrFail($data['returned_detail_id']);
            $originalSale   = $returnedDetail->sale;

            $newItemData  = $data['new_item'];
            $newPsId      = (int) $newItemData['product_size_id'];
            $newColorId   = (int) ($newItemData['color_id'] ?? 0) > 0 ? (int) $newItemData['color_id'] : null;

            // Cantidad del nuevo ítem: explícita en el payload o igual a la devuelta.
            $qty = (int) ($newItemData['quantity'] ?? $returnedDetail->quantity);

            // ── Info del nuevo producto ──────────────────────────────────────────────
            $newProdInfo = DB::table('product_size')
                ->join('products', 'product_size.product_id', '=', 'products.id')
                ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                ->where('product_size.id', $newPsId)
                ->select(
                    'products.name as pname',
                    'sizes.description as sname',
                    'products.id as pid',
                    'sizes.id as sid'
                )
                ->first();

            if (!$newProdInfo) {
                throw new Exception("El nuevo producto (product_size ID: {$newPsId}) no existe.");
            }

            $newColorName    = $newColorId
                ? DB::table('colors')->where('id', $newColorId)->value('description') ?? 'Único'
                : 'Único';
            $newItemFullName = "{$newProdInfo->pname} ({$newProdInfo->sname} | {$newColorName})";

            // ── A. Restaurar stock del producto devuelto ─────────────────────────────
            $returnedPsId = $this->inventory->resolveProductSizeId(
                $returnedDetail->product_id,
                $returnedDetail->size_id
            );

            if ($returnedPsId) {
                $stockBeforeReturn = $this->inventory->increment(
                    $returnedPsId,
                    $returnedDetail->color_id,
                    $returnedDetail->quantity
                );

                $this->inventory->recordHistory(
                    productId:   $returnedDetail->product_id,
                    entityType:  'EXCHANGE',
                    entityId:    $originalSale->id,
                    eventType:   'RETURNED',
                    stockBefore: $stockBeforeReturn,
                    stockAfter:  $stockBeforeReturn + $returnedDetail->quantity,
                    extra: [
                        'quantity'      => $returnedDetail->quantity,
                        'unit_price'    => $returnedDetail->unit_price,
                        'sale_code'     => $originalSale->code,
                        'size_name'     => $returnedDetail->size_name_snapshot,
                        'color_name'    => $returnedDetail->color_name_snapshot,
                        'exchange_note' => "Cambiado por: {$newItemFullName}",
                    ]
                );
            }

            // ── B. Descontar stock del nuevo producto ────────────────────────────────
            // decrement() valida stock disponible antes de escribir.
            $stockBeforeNew = $this->inventory->decrement($newPsId, $newColorId, $qty);

            $newPrice = isset($newItemData['final_price'])
                ? (float) $newItemData['final_price']
                : (float) (DB::table('product_size')->where('id', $newPsId)->value('sale_price') ?? 0);

            $returnedFullName = "{$returnedDetail->product_name_snapshot} "
                . "({$returnedDetail->size_name_snapshot} | {$returnedDetail->color_name_snapshot})";

            $this->inventory->recordHistory(
                productId:   $newProdInfo->pid,
                entityType:  'EXCHANGE',
                entityId:    $originalSale->id,
                eventType:   'TAKEN',
                stockBefore: $stockBeforeNew,
                stockAfter:  $stockBeforeNew - $qty,
                extra: [
                    'quantity'      => $qty,
                    'unit_price'    => $newPrice,
                    'sale_code'     => $originalSale->code,
                    'size_name'     => $newProdInfo->sname,
                    'color_name'    => $newColorName,
                    'exchange_note' => "Cambio desde: {$returnedFullName}",
                ]
            );

            // ── C. Crear detalle del nuevo ítem ──────────────────────────────────────
            $originalSale->details()->create([
                'product_id'            => $newProdInfo->pid,
                'size_id'               => $newProdInfo->sid,
                'color_id'              => $newColorId,
                'product_name_snapshot' => $newProdInfo->pname,
                'size_name_snapshot'    => $newProdInfo->sname,
                'color_name_snapshot'   => $newColorName,
                'quantity'              => $qty,
                'unit_price'            => $newPrice,
                'subtotal'              => $newPrice * $qty,
            ]);

            $returnedDetail->delete();

            // ── D. Actualizar total y pagos ──────────────────────────────────────────
            $newTotal = $originalSale->details()->sum('subtotal');
            $originalSale->update(['total_amount' => $newTotal]);

            $originalSale->payments()->delete();
            $newPaymentMethod = $data['payment_method'] ?? 'CASH';
            $originalSale->payments()->create([
                'method'     => $newPaymentMethod,
                'amount'     => $newTotal,
                'reference'  => 'CAMBIO MERCADERÍA',
                'created_at' => now(),
            ]);
            $originalSale->update(['payment_method' => $newPaymentMethod]);

            // ── E. Diferencia de precio a Caja Chica ────────────────────────────────
            $difference = $data['difference_amount'] ?? 0;
            if ($difference > 0) {
                CashMovement::create([
                    'type'            => 'INCOME',
                    'amount'          => $difference,
                    'description'     => "Cambio Mercadería #{$originalSale->code} (Diferencia)",
                    'payment_method'  => $newPaymentMethod,
                    'creation_time'   => now(),
                    'creator_user_id' => Auth::id(),
                ]);
            }

            $note = "CAMBIO: Entregó {$returnedDetail->product_name_snapshot}, "
                . "Llevó {$newProdInfo->pname} (S/ {$newPrice}).";
            $originalSale->notes = substr(($originalSale->notes ?? '') . ' | ' . $note, -250);
            $originalSale->save();

            return true;
        });
    }

    /**
     * Genera el snapshot de nombres/IDs al sustituir un producto en un detalle.
     */
    private function getNewProductSnapshot(int $psId, ?int $colorId): array
    {
        $data = DB::table('product_size as ps')
            ->join('products as p', 'ps.product_id', '=', 'p.id')
            ->join('sizes as s', 'ps.size_id', '=', 's.id')
            ->where('ps.id', $psId)
            ->select(
                'p.id as product_id',
                's.id as size_id',
                'p.name as product_name',
                's.description as size_name'
            )
            ->first();

        $colorName = $colorId
            ? DB::table('colors')->where('id', $colorId)->value('description') ?? 'Único'
            : 'Único';

        return [
            'product_id'            => $data->product_id,
            'size_id'               => $data->size_id,
            'product_name_snapshot' => $data->product_name,
            'size_name_snapshot'    => $data->size_name,
            'color_name_snapshot'   => $colorName,
        ];
    }
}

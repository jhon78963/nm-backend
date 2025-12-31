<?php

namespace App\Finance\Sale\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Collection;

class SaleService extends ModelService
{
    public function __construct(Sale $sale)
    {
        parent::__construct($sale);
    }

    public function getMonthlyStats(): Collection
    {
        // 1. INGRESOS: Sacamos directamente de 'sales' sumando 'total_amount'
        // Esto evita duplicidad de montos al no hacer join con detalles aquí.
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

        // 2. COSTOS: Calculamos en base al precio de compra del producto (product_size)
        // Aquí sí necesitamos el detalle para saber qué productos se vendieron.
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

        // 3. FUSIÓN: Unimos ambos datos y calculamos la GANANCIA
        return $revenues->map(function ($item) use ($costs) {
            // Asignamos el costo correspondiente al mes, o 0 si no hay datos
            $item->total_cost = $costs[$item->month_year] ?? 0;

            // Calculamos la Ganancia (Ingreso - Costo)
            $item->profit = $item->total_revenue - $item->total_cost;

            return $item;
        })->values();
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model|null {
            $model->fill($data);
            $model->save();

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

                $newGrandTotal = $model->details()->sum('subtotal');
                $model->update(['total_amount' => $newGrandTotal]);
            }

            if (isset($data['payments']) && is_array($data['payments'])) {
                $model->payments()->delete();
                foreach ($data['payments'] as $payment) {
                    $model->payments()->create([
                        'method' => $payment['method'],
                        'amount' => $payment['amount'],
                        'reference' => $payment['reference'] ?? null,
                    ]);
                }

                $mainMethod = count($data['payments']) > 1 ? 'MIXTO' : $data['payments'][0]['method'];
                $model->update(['payment_method' => $mainMethod]);

            } else {
                if ($model->payments()->count() === 1) {
                    $model->payments()->first()->update(['amount' => $model->total_amount]);
                }
            }

            return $model->fresh(['details', 'payments']);
        });
    }

    public function processPosSale(array $data): Sale
    {
        return DB::transaction(function () use ($data): Model {

            $payments = $data['payments'] ?? [];
            if (empty($payments)) {
                $payments = [
                    [
                        'method' => 'CASH',
                        'amount' => $data['total'],
                        'reference' => null
                    ]
                ];
            }

            $mainMethod = count($payments) > 1 ? 'MIXTO' : $payments[0]['method'];
            $sale = $this->create([
                'customer_id' => $data['customer_id'] ?? null, // Usamos null coalescing por si viene plano
                'total_amount' => $data['total'],
                'payment_method' => $mainMethod,
                'status' => 'COMPLETED',
                'code' => 'V-' . time(),
                'creation_time' => now(),
            ]);

            foreach ($payments as $payment) {
                $sale->payments()->create([
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                ]);
            }

            foreach ($data['items'] as $item) {
                $productSizeId = $item['product_size_id'];
                $colorId = $item['color_id'];
                $qty = $item['quantity'];
                $masterInventory = DB::table('product_size')
                    ->where('id', $productSizeId)
                    ->lockForUpdate()
                    ->first();

                if (!$masterInventory) {
                    throw new Exception("Talla no encontrada ID: $productSizeId");
                }

                DB::table('product_size')->where('id', $productSizeId)->decrement('stock', $qty);

                if ($colorId > 0) {
                    $colorInventory = DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->lockForUpdate()
                        ->first();

                    if (!$colorInventory) {
                        throw new Exception("Error integridad Color ID: $colorId");
                    }

                    DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->decrement('stock', $qty);
                }

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

                $colorName = ($colorId > 0)
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
            }

            return $sale;
        });
    }

    public function processExchange(array $data)
    {
        return DB::transaction(function () use ($data) {
            // A. ITEM QUE ENTRA (DEVOLUCIÓN)
            $returnedDetail = SaleDetail::with('sale')->findOrFail($data['returned_detail_id']);
            $originalSale = $returnedDetail->sale;

            // 1. Restaurar Stock
            $returnedPsId = DB::table('product_size')
                ->where('product_id', $returnedDetail->product_id)
                ->where('size_id', $returnedDetail->size_id)
                ->value('id');

            if ($returnedPsId) {
                DB::table('product_size')->where('id', $returnedPsId)->increment('stock', $returnedDetail->quantity);
                if ($returnedDetail->color_id) {
                    DB::table('product_size_color')
                        ->where('product_size_id', $returnedPsId)
                        ->where('color_id', $returnedDetail->color_id)
                        ->increment('stock', $returnedDetail->quantity);
                }
            }

            // B. ITEM QUE SALE (NUEVO)
            $newItemData = $data['new_item'];
            $newPsId = $newItemData['product_size_id'];
            $newColorId = $newItemData['color_id'];

            // PRIORIDAD: Usar final_price del payload. Si no existe, buscar en BD.
            $newPrice = isset($newItemData['final_price'])
                ? (float) $newItemData['final_price']
                : (DB::table('product_size')->where('id', $newPsId)->value('sale_price') ?? 0);

            $qty = 1;

            // 2. Descontar Stock Nuevo
            $masterInventory = DB::table('product_size')->where('id', $newPsId)->lockForUpdate()->first();
            if (!$masterInventory)
                throw new Exception("Talla nueva no encontrada.");

            DB::table('product_size')->where('id', $newPsId)->decrement('stock', $qty);

            if ($newColorId > 0) {
                DB::table('product_size_color')
                    ->where('product_size_id', $newPsId)
                    ->where('color_id', $newColorId)
                    ->decrement('stock', $qty);
            }

            // 3. Crear Detalle Nuevo
            $sizeInfo = DB::table('product_size')
                ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                ->join('products', 'product_size.product_id', '=', 'products.id')
                ->where('product_size.id', $newPsId)
                ->select('products.id as pid', 'products.name as pname', 'sizes.description as sname', 'sizes.id as sid')
                ->first();

            $colorName = $newColorId > 0
                ? (DB::table('colors')->where('id', $newColorId)->value('description') ?? 'Desconocido')
                : 'Único';

            $originalSale->details()->create([
                'product_id' => $sizeInfo->pid,
                'size_id' => $sizeInfo->sid,
                'color_id' => $newColorId > 0 ? $newColorId : null,
                'product_name_snapshot' => $sizeInfo->pname,
                'size_name_snapshot' => $sizeInfo->sname,
                'color_name_snapshot' => $colorName,
                'quantity' => $qty,
                'unit_price' => $newPrice,
                'subtotal' => $newPrice * $qty
            ]);

            // 4. Eliminar Detalle Antiguo
            $returnedDetail->delete();

            // 5. Actualizar Total Venta
            $newTotal = $originalSale->details()->sum('subtotal');
            $originalSale->update(['total_amount' => $newTotal]);

            // 6. ACTUALIZAR PAGOS (SALE PAYMENTS) - REEMPLAZO TOTAL
            // Borramos historial antiguo de pagos de esta venta
            $originalSale->payments()->delete();

            // Creamos UN solo pago nuevo con el total actual y la fecha de hoy
            // Usando el método de pago especificado en el cambio
            $newPaymentMethod = $data['payment_method'] ?? 'CASH';

            $originalSale->payments()->create([
                'method' => $newPaymentMethod,
                'amount' => $newTotal, // El total de la venta actual (ej. 20)
                'reference' => 'CAMBIO MERCADERÍA',
                'created_at' => now() // Fecha actual del cambio
            ]);

            // Actualizamos la cabecera con el nuevo método único
            $originalSale->update(['payment_method' => $newPaymentMethod]);

            // 7. REGISTRAR EN CAJA CHICA (Solo la diferencia real que entra hoy)
            $difference = $data['difference_amount'];
            if ($difference > 0) {
                CashMovement::create([
                    'type' => 'INCOME',
                    'amount' => $difference,
                    'description' => "Cambio Mercadería #{$originalSale->code} (Diferencia)",
                    'payment_method' => $newPaymentMethod,
                    'creation_time' => now(),
                    'creator_user_id' => auth()->id()
                ]);
            }

            // 8. Notas
            $note = "CAMBIO: Entregó {$returnedDetail->product_name_snapshot}, Llevó {$sizeInfo->pname} (S/ {$newPrice}).";
            $originalSale->notes = trim(($originalSale->notes ?? '') . " | " . $note);
            $originalSale->save();

            return true;
        });
    }
}

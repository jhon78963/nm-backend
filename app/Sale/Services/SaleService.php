<?php

namespace App\Sale\Services;

use App\Sale\Models\Sale;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Exception;

class SaleService extends ModelService
{
    public function __construct(Sale $sale)
    {
        parent::__construct($sale);
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {

            // 1. Actualizar campos base (fecha)
            parent::update($model, $data);

            // 2. Actualizar Precios de Ítems (Si vienen en el request)
            if (!empty($data['items']) && is_array($data['items'])) {

                foreach ($data['items'] as $itemData) {
                    $detail = $model->details()->where('id', $itemData['id'])->first();

                    if ($detail) {
                        $newPrice = (float) $itemData['unit_price'];
                        $quantity = (int) $detail->quantity;
                        $newSubtotal = $newPrice * $quantity;

                        $detail->update([
                            'unit_price' => $newPrice,
                            'subtotal' => $newSubtotal
                        ]);
                    }
                }

                // 3. Recalcular el Total General
                $newGrandTotal = $model->details()->sum('subtotal');

                $model->update([
                    'total_amount' => $newGrandTotal
                ]);

                // --- CORRECCIÓN: SINCRONIZAR PAGO ÚNICO ---
                // Si la venta tiene exactamente 1 pago registrado, lo actualizamos
                // para que coincida con el nuevo total.
                if ($model->payments()->count() === 1) {
                    $model->payments()->first()->update([
                        'amount' => $newGrandTotal
                    ]);
                }
            }

            return $model->fresh(['details', 'payments']);
        });
    }

    // public function update(Model $model, array $data): Model
    // {
    //     return DB::transaction(function () use ($model, $data) {

    //         parent::update($model, $data);
    //         if (!empty($data['items']) && is_array($data['items'])) {

    //             foreach ($data['items'] as $itemData) {
    //                 $detail = $model->details()->where('id', $itemData['id'])->first();
    //                 if ($detail) {
    //                     $newPrice = (float) $itemData['unit_price'];
    //                     $quantity = (int) $detail->quantity;
    //                     $newSubtotal = $newPrice * $quantity;
    //                     $detail->update([
    //                         'unit_price' => $newPrice,
    //                         'subtotal' => $newSubtotal
    //                     ]);
    //                 }
    //             }

    //             $newGrandTotal = $model->details()->sum('subtotal');
    //             $model->update([
    //                 'total_amount' => $newGrandTotal
    //             ]);
    //         }

    //         return $model->fresh(['details']);
    //     });
    // }

    public function processPosSale(array $data): Sale
    {
        return DB::transaction(function () use ($data) {

            // 1. RECUPERAR PAGOS
            // Si validaste en el controller, $data['payments'] tendrá tus 2 pagos (Yape/Efectivo).
            // Si no, entrará al fallback de CASH.
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

            // 2. DEFINIR MÉTODO PRINCIPAL (Para la cabecera)
            $mainMethod = count($payments) > 1 ? 'MIXTO' : $payments[0]['method'];

            // 3. CREAR VENTA (Cabecera)
            $sale = $this->create([
                'customer_id' => $data['customer_id'] ?? null, // Usamos null coalescing por si viene plano
                'total_amount' => $data['total'],
                'payment_method' => $mainMethod,
                'status' => 'COMPLETED',
                'code' => 'V-' . time(),
                'creation_time' => now(),
            ]);

            // 4. GUARDAR LOS PAGOS DETALLADOS
            // Aquí es donde se guardan los 6 de Efectivo y 7 de Yape
            foreach ($payments as $payment) {
                // Opción A: Usando relación (Si definiste hasMany en Sale)
                $sale->payments()->create([
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                ]);

                // Opción B: Directo (Si te da error de relación, usa esta)
                /*
                \App\Sales\Models\SalePayment::create([
                    'sale_id'   => $sale->id,
                    'method'    => $payment['method'],
                    'amount'    => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                ]);
                */
            }

            // 5. PROCESAR ÍTEMS E INVENTARIO
            foreach ($data['items'] as $item) {
                $productSizeId = $item['product_size_id'];
                $colorId = $item['color_id'];
                $qty = $item['quantity'];

                // --- DESCUENTO DE STOCK ---
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

                // --- SNAPSHOTS PARA EL TICKET ---
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
}

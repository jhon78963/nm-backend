<?php

namespace App\Sales\Services;

use App\Sales\Models\Sale;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Support\Facades\DB;
use Exception;

class SaleService extends ModelService
{
    public function __construct(Sale $sale)
    {
        parent::__construct($sale);
    }

    public function processPosSale(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            // 1. Crear Cabecera
            $sale = $this->create([
                'customer_id' => $data['customer_id'],
                'total_amount' => $data['total'],
                'payment_method' => $data['payment_method'] ?? 'CASH',
                'status' => 'COMPLETED',
                'code' => 'V-' . time(),
            ]);

            // 2. Procesar Ítems
            foreach ($data['items'] as $item) {

                $productSizeId = $item['product_size_id'];
                $colorId = $item['color_id'];
                $qty = $item['quantity'];

                // =========================================================
                // PASO A: DESCONTAR STOCK MAESTRO (Tabla product_size)
                // =========================================================
                // Siempre restamos de aquí porque representa la existencia física de la talla,
                // tenga color o no.

                $masterInventory = DB::table('product_size')
                    ->where('id', $productSizeId)
                    ->lockForUpdate()
                    ->first();

                if (!$masterInventory) {
                    throw new Exception("La talla seleccionada no existe en la base de datos.");
                }

                // Opcional: Validar stock maestro.
                // A veces el stock maestro se descuadra del detalle, pero es bueno validar.
                if ($masterInventory->stock < $qty) {
                    throw new Exception("Stock general insuficiente para esta talla (Quedan: {$masterInventory->stock}).");
                }

                DB::table('product_size')
                    ->where('id', $productSizeId)
                    ->decrement('stock', $qty);


                // =========================================================
                // PASO B: DESCONTAR STOCK DETALLADO (Tabla product_size_color)
                // =========================================================
                // Solo si se seleccionó un color específico (ID > 0)

                if ($colorId > 0) {
                    $colorInventory = DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->lockForUpdate()
                        ->first();

                    // Validación estricta del detalle
                    if (!$colorInventory) {
                        // Si llegamos aquí, es un error de integridad (hay talla, pero no la relación con el color)
                        throw new Exception("Error crítico: No existe la relación Talla-Color para el ID: {$colorId}");
                    }

                    if ($colorInventory->stock < $qty) {
                        throw new Exception("Stock insuficiente para el color seleccionado (Quedan: {$colorInventory->stock}).");
                    }

                    DB::table('product_size_color')
                        ->where('product_size_id', $productSizeId)
                        ->where('color_id', $colorId)
                        ->decrement('stock', $qty);
                }

                // =========================================================
                // PASO C: GUARDAR HISTÓRICO (SNAPSHOTS)
                // =========================================================

                // Recuperamos nombres para la constancia
                $sizeInfo = DB::table('product_size')
                    ->join('sizes', 'product_size.size_id', '=', 'sizes.id')
                    ->join('products', 'product_size.product_id', '=', 'products.id')
                    ->where('product_size.id', $productSizeId)
                    ->select('products.id as pid', 'products.name as pname', 'sizes.description as sname', 'sizes.id as sid')
                    ->first();

                $colorName = 'Único';
                if ($colorId > 0) {
                    $colorName = DB::table('colors')->where('id', $colorId)->value('description') ?? 'Desconocido';
                }

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

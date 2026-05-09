<?php

namespace App\Inventory\Support;

use App\Inventory\Product\Models\ProductHistory;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad para las modificaciones de stock.
 *
 * - Toda operación adquiere lockForUpdate() sobre product_size ANTES de
 *   leer o escribir, eliminando race conditions en ediciones concurrentes.
 * - decrement() lanza una excepción si el stock resultante sería negativo,
 *   impidiendo saldos negativos en la base de datos.
 * - Ambos métodos retornan el stock efectivo ANTES de la operación para
 *   que el llamador pueda registrar el historial con valores exactos.
 *
 * Debe invocarse siempre dentro de DB::transaction().
 */
class InventoryManager
{
    /**
     * Incrementa stock en product_size y, si aplica, en product_size_color.
     * Retorna el stock del nivel relevante antes del incremento.
     *
     * @throws Exception si la fila product_size no existe.
     */
    public function increment(int $psId, ?int $colorId, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }

        $masterRow = DB::table('product_size')
            ->where('id', $psId)
            ->lockForUpdate()
            ->first();

        if (!$masterRow) {
            throw new Exception("Talla de producto (product_size ID: {$psId}) no encontrada.");
        }

        $stockBefore = $colorId
            ? $this->lockColorRowAndGetStock($psId, $colorId)
            : (int) $masterRow->stock;

        DB::table('product_size')->where('id', $psId)->increment('stock', $qty);

        if ($colorId) {
            DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->increment('stock', $qty);
        }

        return $stockBefore;
    }

    /**
     * Decrementa stock con bloqueo y valida que no quede negativo.
     * Retorna el stock del nivel relevante antes del decremento.
     *
     * @throws Exception si no hay stock suficiente o la fila no existe.
     */
    public function decrement(int $psId, ?int $colorId, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }

        $masterRow = DB::table('product_size')
            ->where('id', $psId)
            ->lockForUpdate()
            ->first();

        if (!$masterRow) {
            throw new Exception("Talla de producto (product_size ID: {$psId}) no encontrada.");
        }

        if ($colorId) {
            $colorStock = $this->lockColorRowAndGetStock($psId, $colorId);

            if ($colorStock < $qty) {
                throw new Exception(
                    "Stock insuficiente para el color (color_id: {$colorId}). " .
                    "Disponible: {$colorStock}, solicitado: {$qty}."
                );
            }

            $stockBefore = $colorStock;

            DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->decrement('stock', $qty);
        } else {
            $masterStock = (int) $masterRow->stock;

            if ($masterStock < $qty) {
                throw new Exception(
                    "Stock insuficiente (product_size ID: {$psId}). " .
                    "Disponible: {$masterStock}, solicitado: {$qty}."
                );
            }

            $stockBefore = $masterStock;
        }

        DB::table('product_size')->where('id', $psId)->decrement('stock', $qty);

        return $stockBefore;
    }

    /**
     * Resuelve el ID de product_size a partir de product_id + size_id.
     */
    public function resolveProductSizeId(int $productId, int $sizeId): ?int
    {
        return DB::table('product_size')
            ->where('product_id', $productId)
            ->where('size_id', $sizeId)
            ->value('id');
    }

    /**
     * Registra un movimiento en ProductHistory con valores matemáticamente
     * consistentes con la operación real ejecutada en BD.
     */
    public function recordHistory(
        int    $productId,
        string $entityType,
        int    $entityId,
        string $eventType,
        int    $stockBefore,
        int    $stockAfter,
        array  $extra = []
    ): void {
        ProductHistory::create([
            'product_id'      => $productId,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'event_type'      => $eventType,
            'old_values'      => ['stock' => $stockBefore],
            'new_values'      => array_merge(['stock' => $stockAfter], $extra),
            'creator_user_id' => Auth::id(),
        ]);
    }

    /**
     * Adquiere lockForUpdate sobre product_size_color y retorna su stock actual.
     * Retorna 0 si la fila aún no existe (producto sin ese color registrado).
     */
    private function lockColorRowAndGetStock(int $psId, int $colorId): int
    {
        $colorRow = DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->where('color_id', $colorId)
            ->lockForUpdate()
            ->first();

        return $colorRow ? (int) $colorRow->stock : 0;
    }
}

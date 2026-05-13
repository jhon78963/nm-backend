<?php

namespace App\Inventory\Concerns;

/**
 * Clave lexicográfica estable para ordenar bloqueos de inventario (maestro/detalle)
 * y evitar deadlocks entre transacciones concurrentes.
 *
 * Regla: NUNCA ordenar solo por product_size.id; siempre por (product_id, size_id).
 */
trait ProvidesInventoryLockSortKey
{
    protected function getInventoryLockSortKey(int $productId, int $sizeId): string
    {
        return sprintf('%010d-%010d', $productId, $sizeId);
    }
}

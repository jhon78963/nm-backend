<?php

namespace App\Inventory\Support;

final class StockAvailability
{
    /**
     * Tras `lockForUpdate()`: evita decrements que dejarían stock negativo.
     *
     * @throws \Exception
     */
    public static function assertCanDecrement(int $availableStock, int $quantityToRemove): void
    {
        if ($quantityToRemove > $availableStock) {
            throw new \Exception(
                "Stock insuficiente. Unidades disponibles: {$availableStock}, solicitadas: {$quantityToRemove}."
            );
        }
    }
}

<?php

namespace App\Policies;

use App\Administration\User\Models\User;
use App\Finance\Sale\Models\Sale;

class SalePolicy
{
    /**
     * Crear venta vía POS (checkout).
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pos.checkout');
    }

    /**
     * Ver detalle / ticket de una venta.
     */
    public function view(User $user, Sale $sale): bool
    {
        return $user->hasPermissionTo('sale.get')
            && $this->belongsToUserWarehouse($user, $sale);
    }

    /**
     * Editar o anular una venta existente.
     */
    public function update(User $user, Sale $sale): bool
    {
        return $user->hasPermissionTo('sale.update')
            && $this->belongsToUserWarehouse($user, $sale);
    }

    private function belongsToUserWarehouse(User $user, Sale $sale): bool
    {
        $userWarehouseId = (int) ($user->warehouse_id ?? 0);
        if ($userWarehouseId < 1) {
            return false;
        }

        return (int) $sale->warehouse_id === $userWarehouseId;
    }
}

<?php

namespace App\Inventory\InventoryLedger\Rules;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\ValidationRule;

final class AccessibleWarehouseForActor implements ValidationRule
{
    public function __construct(
        private readonly ?Authenticatable $user = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $warehouseId = (int) $value;
        if ($warehouseId < 1) {
            $fail('El almacén indicado no es válido.');

            return;
        }

        $user = $this->user ?? auth()->user();
        if ($user === null) {
            $fail('No autenticado.');

            return;
        }

        if (! WarehouseIdForInventoryResolver::userCanAccessWarehouse($warehouseId, $user)) {
            $fail('No tiene permiso para usar este almacén.');
        }
    }
}

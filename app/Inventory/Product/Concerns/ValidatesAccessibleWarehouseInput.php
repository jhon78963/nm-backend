<?php

namespace App\Inventory\Product\Concerns;

use App\Inventory\InventoryLedger\Rules\AccessibleWarehouseForActor;
use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;

trait ValidatesAccessibleWarehouseInput
{
    protected function authorizesRequestedWarehouse(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $warehouseId = $this->requestedWarehouseId();

        if ($warehouseId === null) {
            return true;
        }

        if (! WarehouseIdForInventoryResolver::userCanAccessWarehouse($warehouseId, $user)) {
            throw new AuthorizationException(
                'No tiene permiso para acceder a este almacén.',
            );
        }

        return true;
    }

    protected function requestedWarehouseId(): ?int
    {
        $fromCamel = $this->input('warehouseId');
        if ($fromCamel !== null && $fromCamel !== '') {
            return (int) $fromCamel;
        }

        $fromSnake = $this->input('warehouse_id');
        if ($fromSnake !== null && $fromSnake !== '') {
            return (int) $fromSnake;
        }

        return null;
    }

    /**
     * @return list<\Illuminate\Contracts\Validation\ValidationRule|string>
     */
    protected function accessibleWarehouseRule(string $presence = 'nullable'): array
    {
        $user = $this->user();
        $tenantId = (int) ($user?->tenant_id ?? 0);

        $existsRule = Rule::exists('warehouses', 'id');
        if ($tenantId > 0) {
            $existsRule = Rule::exists('warehouses', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId),
            );
        }

        return [
            $presence,
            'integer',
            'min:1',
            $existsRule,
            new AccessibleWarehouseForActor($user),
        ];
    }
}

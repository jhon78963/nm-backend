<?php

namespace App\Auth\Providers;

use App\Finance\Sale\Models\Sale;
use App\Policies\SalePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Sale::class, SalePolicy::class);

        Gate::before(function ($user, string $ability) {
            // Super Admin omite comprobaciones de permisos Spatie (abilities de UI/API).
            // El aislamiento de datos por almacén es independiente: WarehouseScope y
            // WarehouseQueryFilter siempre aplican where(warehouse_id, ...) y no se
            // saltan aquí. Super Admin solo puede consultar otro almacén si envía
            // warehouse_id / warehouseId / X-Warehouse-Id explícito en la petición.
            if ($user && method_exists($user, 'hasRole') && $user->hasRole('Super Admin')) {
                return true;
            }

            return null;
        });
    }
}

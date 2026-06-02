<?php

namespace App\Auth\Providers;

use App\Administration\User\Models\User;
use App\Directory\Team\Models\TeamPayment;
use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Policies\CashMovementPolicy;
use App\Policies\RolePolicy;
use App\Policies\SalePolicy;
use App\Policies\TeamPaymentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(TeamPayment::class, TeamPaymentPolicy::class);
        Gate::policy(CashMovement::class, CashMovementPolicy::class);

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

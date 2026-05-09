<?php

namespace App\Shared\Foundation\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope global que inyecta automáticamente un filtro por tenant_id en todas
 * las consultas del modelo que lo use (mediante el trait BelongsToTenant).
 *
 * Comportamiento:
 *  - Si no hay usuario autenticado: no aplica el filtro (por ejemplo en seeders/artisan).
 *  - Si el usuario tiene el rol 'Super Admin': no aplica el filtro (visión global).
 *  - En cualquier otro caso: filtra siempre por tenant_id del usuario autenticado.
 *
 * El scope se cualifica con el nombre de la tabla del modelo para evitar
 * ambigüedades en JOINs (igual que WarehouseScope).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if (method_exists($user, 'hasRole') && $user->hasRole('Super Admin')) {
            return;
        }

        if ($user->tenant_id) {
            $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
        }
    }
}

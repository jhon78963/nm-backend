<?php

namespace App\Shared\Foundation\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope de tenant específico para el modelo Role.
 *
 * A diferencia de TenantScope, este scope NO llama a hasRole() para
 * detectar al Super Admin, lo que evita una recursión infinita
 * (TenantScope → hasRole() → carga roles → TenantScope → ...).
 *
 * La lógica de visibilidad se basa únicamente en tenant_id:
 *  - tenant_id === null  → Super Admin sin tenant asignado → ve todos los roles.
 *  - tenant_id === X     → usuario de tenant X → solo ve los roles de su tenant.
 *
 * Para consultas globales (SuperAdmin vía panel) se puede escapar con:
 *   Role::withoutGlobalScope(RoleTenantScope::class)->get();
 */
class RoleTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if ($user->tenant_id === null) {
            return;
        }

        $builder->where($model->getTable().'.tenant_id', $user->tenant_id);
    }
}

<?php

namespace App\Administration\Role\Models;

use App\Administration\Tenant\Models\Tenant;
use App\Shared\Foundation\Scopes\RoleTenantScope;
use App\Shared\Foundation\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Modelo Role personalizado que extiende el de Spatie y añade
 * aislamiento multi-tenant automático.
 *
 * Usamos el trait BelongsToTenant para obtener la relación tenant()
 * y el auto-relleno de tenant_id en creación, pero sobreescribimos
 * bootBelongsToTenant para registrar RoleTenantScope en lugar de
 * TenantScope, evitando la recursión infinita que ocurriría si
 * TenantScope llamara a hasRole() mientras se cargan los propios roles.
 */
class Role extends SpatieRole
{
    use BelongsToTenant;

    /**
     * Sobreescribe el boot del trait para registrar el scope seguro
     * (sin llamadas a hasRole) en lugar del TenantScope genérico.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new RoleTenantScope());

        static::creating(function (self $model) {
            if (empty($model->tenant_id) && auth()->check()) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    /**
     * Relación al tenant propietario de este rol.
     * (Duplicada aquí para dejar explícita la FK; el trait ya la
     * proporciona, pero la sobreescritura del boot la mantiene activa.)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

<?php

namespace App\Shared\Foundation\Traits;

use App\Administration\Tenant\Models\Tenant;
use App\Shared\Foundation\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait para aislamiento multi-tenant automático.
 *
 * Al incluirlo en un modelo, registra el TenantScope como scope global,
 * de forma que TODAS las consultas queden filtradas por el tenant del usuario
 * autenticado. También rellena automáticamente tenant_id al crear registros.
 *
 * Uso:
 *   class Sale extends Model {
 *       use BelongsToTenant;
 *   }
 *
 * Para escapar el scope puntualmente (p.ej. en un comando Artisan):
 *   Sale::withoutGlobalScope(TenantScope::class)->get();
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        // Inyecta tenant_id automáticamente en la creación si no viene ya definido.
        static::creating(function (self $model) {
            if (empty($model->tenant_id) && auth()->check()) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

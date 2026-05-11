<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Traits;

use App\Modules\Shared\Domain\TenantContext;
use App\Modules\Shared\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = TenantContext::id();

            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }
}

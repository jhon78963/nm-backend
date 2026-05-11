<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Scopes;

use App\Modules\Shared\Domain\Contracts\SkipsStrictTenantScopeWhenContextMissing;
use App\Modules\Shared\Domain\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            if ($model instanceof SkipsStrictTenantScopeWhenContextMissing) {
                return;
            }

            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where(
            sprintf('%s.tenant_id', $model->getTable()),
            $tenantId,
        );
    }
}

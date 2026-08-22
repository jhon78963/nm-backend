<?php

namespace App\Administration\Audit\Support;

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

final class ActionLogVisibility
{
    public static function actorIsSuperAdmin(User $user): bool
    {
        return method_exists($user, 'hasRole')
            && $user->hasRole(SuperAdminRole::NAME);
    }

    /**
     * @return array<string, callable(\Illuminate\Database\Eloquent\Relations\Relation): void>
     */
    public static function eagerLoads(): array
    {
        return [
            'user' => fn ($query) => $query->withoutGlobalScopes(),
            'team' => fn ($query) => $query->withoutGlobalScopes(),
        ];
    }

    /**
     * @param  Builder<\App\Administration\Audit\Models\UserActionLog>  $query
     */
    public static function apply(Builder $query, User $actor): void
    {
        if (self::actorIsSuperAdmin($actor)) {
            $tenantId = (int) ($actor->tenant_id ?? 0);
            if ($tenantId > 0) {
                self::scopeToTenant($query, $tenantId, includeAnonymous: true);
            }

            return;
        }

        $tenantId = (int) ($actor->tenant_id ?? 0);
        if ($tenantId > 0) {
            self::scopeToTenant($query, $tenantId, includeAnonymous: false);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<\App\Administration\Audit\Models\UserActionLog>  $query
     */
    private static function scopeToTenant(
        Builder $query,
        int $tenantId,
        bool $includeAnonymous,
    ): void {
        $warehouseIds = Warehouse::query()
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        $query->where(function (Builder $scoped) use ($tenantId, $warehouseIds, $includeAnonymous): void {
            if ($warehouseIds->isNotEmpty()) {
                $scoped->whereIn('warehouse_id', $warehouseIds);
            }

            $scoped->orWhereHas('user', function (Builder $userQuery) use ($tenantId): void {
                $userQuery->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId);
            });

            if ($includeAnonymous) {
                $scoped->orWhereNull('user_id');
            }
        });
    }
}

<?php

namespace App\Administration\Audit\Support;

use App\Administration\User\Models\User;
use App\Administration\User\Support\SuperAdminRole;
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
                $query->whereHas('user', function (Builder $userQuery) use ($tenantId): void {
                    $userQuery->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId);
                });
            }

            return;
        }

        $warehouseId = (int) ($actor->warehouse_id ?? 0);
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);

            return;
        }

        $query->whereRaw('1 = 0');
    }
}

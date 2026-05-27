<?php

namespace App\Finance\Sale\Support;

use App\Administration\User\Models\User;
use App\Finance\Sale\Models\Sale;
use App\Shared\Foundation\Support\AuthenticatedUserWarehouseResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Alcance de consulta de ventas según rol:
 * - Super Admin / Admin: sin límite de fechas.
 * - Vendedora / Vendedor: solo mes en curso (creation_time).
 */
final class SaleAccessScope
{
    /** @var list<string> */
    public const STORE_SELLER_ROLES = ['Vendedora', 'Vendedor'];

    public static function restrictsToCurrentMonth(?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        if (AuthenticatedUserWarehouseResolver::userHasPrivilegedRole($user)) {
            return false;
        }

        foreach (self::STORE_SELLER_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public static function applyCurrentMonthFilter(
        Builder $query,
        ?Carbon $reference = null,
        string $column = 'creation_time',
    ): void {
        $reference ??= now();

        $query->whereYear($column, $reference->year)
            ->whereMonth($column, $reference->month);
    }

    public static function assertSaleInAccessibleRange(Sale $sale): void
    {
        if (! self::restrictsToCurrentMonth()) {
            return;
        }

        $created = $sale->creation_time;

        if ($created === null) {
            abort(404);
        }

        $reference = now();

        if ((int) $created->year !== (int) $reference->year
            || (int) $created->month !== (int) $reference->month) {
            throw new AccessDeniedHttpException(
                'Solo puede consultar ventas del mes en curso.',
            );
        }
    }
}

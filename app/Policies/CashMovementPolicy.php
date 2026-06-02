<?php

namespace App\Policies;

use App\Administration\User\Models\User;
use App\Finance\CashMovement\Models\CashMovement;

class CashMovementPolicy
{
    public function viewDaily(User $user): bool
    {
        return $user->can('cashflow.getDaily');
    }

    public function viewAdminMonthly(User $user): bool
    {
        return $user->can('cashflow.getAdminMonthlyReport');
    }

    /**
     * @param string $category The category of the movement being created ('ADMINISTRATIVE'|'STORE'|…).
     */
    public function create(User $user, string $category = CashMovement::CATEGORY_STORE): bool
    {
        if (! $user->can('cashflow.store')) {
            return false;
        }

        if ($category === CashMovement::CATEGORY_ADMINISTRATIVE) {
            return $user->can('cashflow.getAdminMonthlyReport');
        }

        return true;
    }

    /**
     * @param string|null $newCategory The category being changed to in the request (if any).
     */
    public function update(User $user, CashMovement $movement, ?string $newCategory = null): bool
    {
        if (! $user->can('cashflow.update')) {
            return false;
        }

        $isAdministrativeInDb = $movement->category === CashMovement::CATEGORY_ADMINISTRATIVE;
        $changingToAdministrative = $newCategory === CashMovement::CATEGORY_ADMINISTRATIVE;

        if ($isAdministrativeInDb || $changingToAdministrative) {
            return $user->can('cashflow.getAdminMonthlyReport');
        }

        return true;
    }
}

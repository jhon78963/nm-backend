<?php

namespace App\Inventory\Product\Support;

use Illuminate\Http\Request;

final class PurchasePriceVisibility
{
    /** @var list<string> */
    private const PRIVILEGED_ROLES = ['Admin', 'Super Admin'];

    private const PERMISSION = 'product.view_purchase_price';

    public static function canView(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasAnyRole(self::PRIVILEGED_ROLES)) {
            return true;
        }

        return $user->can(self::PERMISSION);
    }
}

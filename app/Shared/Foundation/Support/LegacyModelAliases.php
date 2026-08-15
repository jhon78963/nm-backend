<?php

namespace App\Shared\Foundation\Support;

use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\Product\Models\Product;
use App\Inventory\Purchase\Models\Purchase;

/**
 * Alias de clases legacy guardadas en BD (p. ej. reference_type en inventory_movements).
 */
final class LegacyModelAliases
{
    /** @var array<string, class-string> */
    private const MAP = [
        'App\Finances\Sales\Models\Sale' => Sale::class,
        'App\Inventories\Kardex\Models\InventoryBalance' => InventoryBalance::class,
        'App\Inventories\Products\Models\Product' => Product::class,
        'App\Inventories\Purchases\Models\Purchase' => Purchase::class,
    ];

    public static function register(): void
    {
        foreach (self::MAP as $legacy => $current) {
            if (! class_exists($legacy, false) && class_exists($current)) {
                class_alias($current, $legacy);
            }
        }
    }

    public static function resolve(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::MAP[$type] ?? $type;
    }
}

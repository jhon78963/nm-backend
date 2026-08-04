<?php

namespace App\Ai\Support;

/**
 * Detecta productos atacados (lento movimiento + stock alto + antigüedad)
 * y define el nivel de liquidación sugerida.
 *
 * Umbrales alineados con nm_ai_engine/app/ml_models/stock_aging.py
 */
final class DeadStockEvaluator
{
    public const TIER_NONE = 'none';

    public const TIER_AGING = 'aging';

    public const TIER_HIGH = 'high';

    public const TIER_CRITICAL = 'critical';

    /**
     * @return array{
     *     isDeadStock: bool,
     *     tier: string,
     *     clearanceMultiplier: float,
     *     label: string
     * }
     */
    public static function evaluate(
        int $productAgeDays,
        int $daysSinceLastSale,
        int $salesLastMonth,
        int $currentStock,
        int $totalSalesAllTime,
    ): array {
        $idleDays = $daysSinceLastSale > 0 ? $daysSinceLastSale : $productAgeDays;

        if (
            $productAgeDays >= 90
            && $totalSalesAllTime === 0
            && $currentStock >= 5
        ) {
            return self::result(
                self::TIER_CRITICAL,
                0.55,
                'Sin ventas registradas: liquidación urgente recomendada.',
            );
        }

        if (
            $productAgeDays >= 180
            && $salesLastMonth <= 4
            && $currentStock >= 10
            && $idleDays >= 30
        ) {
            return self::result(
                self::TIER_CRITICAL,
                0.60,
                'Atacasco crítico: antigüedad alta, stock elevado y ventas mínimas.',
            );
        }

        if (
            $productAgeDays >= 120
            && $salesLastMonth < 5
            && $currentStock >= 5
            && $idleDays >= 45
        ) {
            return self::result(
                self::TIER_HIGH,
                0.72,
                'Producto estancado: se recomienda rebaja fuerte para liberar capital.',
            );
        }

        if (
            $productAgeDays >= 90
            && $totalSalesAllTime <= 10
            && $currentStock >= 10
            && $idleDays >= 30
        ) {
            return self::result(
                self::TIER_AGING,
                0.80,
                'Rotación muy lenta: descuento adicional para acelerar salida.',
            );
        }

        return self::result(self::TIER_NONE, 1.0, '');
    }

    /**
     * @return array{
     *     isDeadStock: bool,
     *     tier: string,
     *     clearanceMultiplier: float,
     *     label: string
     * }
     */
    private static function result(string $tier, float $multiplier, string $label): array
    {
        return [
            'isDeadStock' => $tier !== self::TIER_NONE,
            'tier' => $tier,
            'clearanceMultiplier' => $multiplier,
            'label' => $label,
        ];
    }
}

<?php

namespace App\Inventory\Product\Support;

use App\Shared\Foundation\Services\SharedService;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Búsqueda de productos por código de barras:
 * - 4–7 dígitos: coincidencia por sufijo (últimos dígitos del código).
 * - 8+ dígitos: escaneo completo (exacto + parcial).
 * - Texto: búsqueda parcial estándar.
 */
final class ProductBarcodeSearch
{
    private const FULL_BARCODE_MIN_LENGTH = 8;

    private const SUFFIX_MIN_LENGTH = 4;

    private const SUFFIX_MAX_LENGTH = 7;

    public static function normalize(?string $search): string
    {
        if ($search === null || $search === '') {
            return '';
        }

        $normalized = preg_replace('/[\s\r\n\t]+/', '', trim($search));

        return is_string($normalized) ? $normalized : '';
    }

    /**
     * @param  array<int, string>  $columnSearch
     */
    public static function apply(
        Builder $query,
        string $search,
        array $columnSearch,
        SharedService $sharedService,
    ): Builder {
        $search = self::normalize($search);

        if ($search === '') {
            return $query;
        }

        $length = strlen($search);

        if (ctype_digit($search) && $length >= self::SUFFIX_MIN_LENGTH && $length <= self::SUFFIX_MAX_LENGTH) {
            return self::applySuffixSearch($query, $search, $columnSearch, $sharedService);
        }

        if (ctype_digit($search) && $length >= self::FULL_BARCODE_MIN_LENGTH) {
            return self::applyFullBarcodeSearch($query, $search, $columnSearch, $sharedService);
        }

        return $sharedService->searchFilter($query, $search, $columnSearch);
    }

    /**
     * @param  array<int, string>  $columnSearch
     */
    private static function applySuffixSearch(
        Builder $query,
        string $search,
        array $columnSearch,
        SharedService $sharedService,
    ): Builder {
        $nonBarcodeColumns = self::nonBarcodeColumns($columnSearch);

        return $query->where(function ($q) use ($search, $nonBarcodeColumns, $sharedService) {
            $q->where('barcode', 'ILIKE', '%'.$search)
                ->orWhereHas('productSizes', function ($sizeQuery) use ($search) {
                    $sizeQuery->where('barcode', 'ILIKE', '%'.$search);
                });

            if ($nonBarcodeColumns !== []) {
                $q->orWhere(function ($inner) use ($search, $nonBarcodeColumns, $sharedService) {
                    $sharedService->searchFilter($inner, $search, $nonBarcodeColumns);
                });
            }
        });
    }

    /**
     * @param  array<int, string>  $columnSearch
     */
    private static function applyFullBarcodeSearch(
        Builder $query,
        string $search,
        array $columnSearch,
        SharedService $sharedService,
    ): Builder {
        return $query->where(function ($q) use ($search, $columnSearch, $sharedService) {
            $q->where('barcode', $search)
                ->orWhereHas('productSizes', function ($sizeQuery) use ($search) {
                    $sizeQuery->where('barcode', $search);
                })
                ->orWhere(function ($inner) use ($search, $columnSearch, $sharedService) {
                    $sharedService->searchFilter($inner, $search, $columnSearch);
                });
        });
    }

    /**
     * @param  array<int, string>  $columnSearch
     * @return array<int, string>
     */
    private static function nonBarcodeColumns(array $columnSearch): array
    {
        return array_values(array_filter(
            $columnSearch,
            static fn (string $column): bool => $column !== 'barcode' && $column !== 'productSizes.barcode',
        ));
    }
}

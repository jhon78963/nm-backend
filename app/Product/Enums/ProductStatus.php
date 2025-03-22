<?php

namespace App\Product\Enums;

enum ProductStatus: string
{
    case Available = 'AVAILABLE';
    case LimitedStock = 'LIMITED_STOCK';
    case OutOfStock = 'OUT_OF_STOCK';
    case Discontinued = 'DISCONTINUED';

    public function label(): string
    {
        return match($this) {
            self::Available => 'Disponible',
            self::LimitedStock => 'Stock limitado',
            self::OutOfStock => 'Fuera de stock',
            self::Discontinued => 'Discontinuado',
        };
    }
}

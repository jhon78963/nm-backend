<?php
namespace App\Inventory\Size\Services;

use App\Inventory\Size\Models\Size;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SizeService extends ModelService
{
    public function __construct(Size $size)
    {
        parent::__construct($size);
    }

    public function getForProductSelection(int $productId, array $sizeTypeIds): Collection
    {
        $productSizes = DB::table('product_size')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('size_id');

        return $this->model
            ->whereIn('size_type_id', $sizeTypeIds)
            ->get()
            ->map(function ($size) use ($productSizes) {
                if ($productSizes->has($size->id)) {
                    $pivot = $productSizes[$size->id];
                    $size->isExists = true;
                    $size->barcode = $pivot->barcode;
                    $size->stock = $pivot->stock;
                    $size->purchasePrice = $pivot->purchase_price;
                    $size->salePrice = $pivot->sale_price;
                    $size->minSalePrice = $pivot->min_sale_price;
                } else {
                    $size->isExists = false;
                    $size->barcode = null;
                    $size->stock = null;
                    $size->purchasePrice = null;
                    $size->salePrice = null;
                    $size->minSalePrice = null;
                }
                return $size;
            })
            ->sortBy(fn($size) => $size->stock === null ? PHP_INT_MAX : $size->id)
            ->values(); // Reindexar
    }
}

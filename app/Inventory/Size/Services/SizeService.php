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
        $productSizes = DB::table('product_size as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.product_id', $productId)
            ->select('ps.*', 'p.warehouse_id as product_warehouse_id')
            ->get()
            ->keyBy('size_id');

        $balanceByProductSizeId = [];
        if ($productSizes->isNotEmpty()) {
            $warehouseId = (int) ($productSizes->first()->product_warehouse_id ?? 0);
            $psIds = $productSizes->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($warehouseId > 0 && $psIds !== []) {
                $balanceByProductSizeId = DB::table('inventory_balances')
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('product_size_id', $psIds)
                    ->select('product_size_id', DB::raw('SUM(quantity) as quantity'))
                    ->groupBy('product_size_id')
                    ->pluck('quantity', 'product_size_id')
                    ->map(static fn ($qty): int => (int) $qty)
                    ->all();
            }
        }

        return $this->model
            ->whereIn('size_type_id', $sizeTypeIds)
            ->get()
            ->map(function ($size) use ($productSizes, $balanceByProductSizeId) {
                if ($productSizes->has($size->id)) {
                    $pivot = $productSizes[$size->id];
                    $size->isExists = true;
                    $size->barcode = $pivot->barcode;
                    $psId = (int) $pivot->id;
                    $size->stock = $balanceByProductSizeId[$psId] ?? 0;
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
            ->sortBy(fn ($size) => $size->stock === null ? PHP_INT_MAX : $size->id)
            ->values();
    }
}

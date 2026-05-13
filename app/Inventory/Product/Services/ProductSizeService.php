<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Support\StockAvailability;
use Illuminate\Support\Facades\DB;

class ProductSizeService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    public function set(Product $product, int $sizeId, array $data): void
    {
        $row = DB::table('product_size')
            ->where('product_id', $product->id)
            ->where('size_id', $sizeId)
            ->lockForUpdate()
            ->first();

        $existingPivot = $row ? [
            'barcode' => $row->barcode,
            'stock' => (int) $row->stock,
            'purchase_price' => $row->purchase_price,
            'sale_price' => $row->sale_price,
            'min_sale_price' => $row->min_sale_price,
        ] : [];

        if (! $row) {
            $stock = array_key_exists('stock', $data) ? (int) $data['stock'] : 0;
            DB::table('product_size')->insert([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'barcode' => $data['barcode'] ?? null,
                'stock' => $stock,
                'purchase_price' => $data['purchasePrice'] ?? null,
                'sale_price' => $data['salePrice'] ?? null,
                'min_sale_price' => $data['minSalePrice'] ?? null,
            ]);
        } else {
            if (array_key_exists('stock', $data)) {
                $currentStock = (int) $row->stock;
                $newStock = (int) $data['stock'];
                if ($newStock !== $currentStock) {
                    $delta = $newStock - $currentStock;
                    if ($delta > 0) {
                        DB::table('product_size')->where('id', $row->id)->increment('stock', $delta);
                    } else {
                        StockAvailability::assertCanDecrement($currentStock, -$delta);
                        DB::table('product_size')->where('id', $row->id)->decrement('stock', -$delta);
                    }
                }
            }

            DB::table('product_size')->where('id', $row->id)->update([
                'barcode' => $data['barcode'] ?? null,
                'purchase_price' => $data['purchasePrice'] ?? null,
                'sale_price' => $data['salePrice'] ?? null,
                'min_sale_price' => $data['minSalePrice'] ?? null,
            ]);
        }

        $fresh = DB::table('product_size')
            ->where('product_id', $product->id)
            ->where('size_id', $sizeId)
            ->lockForUpdate()
            ->first();

        $pivotData = [
            'barcode' => $fresh->barcode,
            'stock' => (int) $fresh->stock,
            'purchase_price' => $fresh->purchase_price,
            'sale_price' => $fresh->sale_price,
            'min_sale_price' => $fresh->min_sale_price,
        ];

        $eventType = $existingPivot === [] ? 'CREATED' : 'UPDATED';

        $this->historyService->logChange(
            $product,
            'SIZE',
            $sizeId,
            $eventType,
            $existingPivot,
            $pivotData
        );
    }

    public function remove(Product $product, int $sizeId): void
    {
        $oldData = $product->sizes()
            ->where('size_id', $sizeId)
            ->first()
            ?->pivot
                ?->toArray();

        $product->sizes()->detach($sizeId);

        if ($oldData) {
            $this->historyService->logChange(
                $product,
                'SIZE',
                $sizeId,
                'DELETED',
                $oldData,
                null
            );
        }
    }

    public function setStock(Product $product, int $sizeId, int $qty, array $data): void
    {
        $row = DB::table('product_size')
            ->where('product_id', $product->id)
            ->where('size_id', $sizeId)
            ->lockForUpdate()
            ->first();

        $existingPivot = $row ? [
            'barcode' => $row->barcode,
            'stock' => (int) $row->stock,
            'purchase_price' => $row->purchase_price,
            'sale_price' => $row->sale_price,
            'min_sale_price' => $row->min_sale_price,
        ] : [];

        if (! $row) {
            DB::table('product_size')->insert([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'barcode' => $data['barcode'] ?? null,
                'stock' => $qty,
                'purchase_price' => $data['purchase_price'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
                'min_sale_price' => $data['min_sale_price'] ?? null,
            ]);
        } else {
            if ($qty < 0) {
                StockAvailability::assertCanDecrement((int) $row->stock, -$qty);
            }
            $newStock = (int) $row->stock + $qty;
            DB::table('product_size')->where('id', $row->id)->update([
                'barcode' => $data['barcode'] ?? null,
                'stock' => $newStock,
                'purchase_price' => $data['purchase_price'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
                'min_sale_price' => $data['min_sale_price'] ?? null,
            ]);
        }

        $fresh = DB::table('product_size')
            ->where('product_id', $product->id)
            ->where('size_id', $sizeId)
            ->lockForUpdate()
            ->first();

        $pivotData = [
            'barcode' => $fresh->barcode,
            'stock' => (int) $fresh->stock,
            'purchase_price' => $fresh->purchase_price,
            'sale_price' => $fresh->sale_price,
            'min_sale_price' => $fresh->min_sale_price,
        ];

        $eventType = empty($existingPivot) ? 'CREATED' : 'UPDATED';

        $this->historyService->logChange(
            $product,
            'SIZE',
            $sizeId,
            $eventType,
            $existingPivot,
            $pivotData
        );
    }
}

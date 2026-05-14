<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSizeService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    public function set(Product $product, int $sizeId, array $data, ?string $auditReason = null): void
    {
        $row = DB::table('product_size')
            ->where('product_id', $product->id)
            ->where('size_id', $sizeId)
            ->lockForUpdate()
            ->first();

        $existingPivot = $row ? [
            'barcode' => $row->barcode,
            'purchase_price' => $row->purchase_price,
            'sale_price' => $row->sale_price,
            'min_sale_price' => $row->min_sale_price,
        ] : [];

        if (! $row) {
            DB::table('product_size')->insert([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'barcode' => $data['barcode'] ?? null,
                'purchase_price' => $data['purchasePrice'] ?? null,
                'sale_price' => $data['salePrice'] ?? null,
                'min_sale_price' => $data['minSalePrice'] ?? null,
            ]);
        } else {
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
            $pivotData,
            $auditReason,
        );
    }

    public function remove(Product $product, int $sizeId): void
    {
        DB::transaction(function () use ($product, $sizeId): void {
            $row = DB::table('product_size')
                ->where('product_id', $product->id)
                ->where('size_id', $sizeId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return;
            }

            $oldData = [
                'barcode' => $row->barcode,
                'purchase_price' => $row->purchase_price,
                'sale_price' => $row->sale_price,
                'min_sale_price' => $row->min_sale_price,
            ];

            DB::table('product_size_color')
                ->where('product_size_id', $row->id)
                ->delete();

            DB::table('product_size')
                ->where('id', $row->id)
                ->delete();

            $this->historyService->logChange(
                $product,
                'SIZE',
                $sizeId,
                'DELETED',
                $oldData,
                null,
            );
        });
    }

}

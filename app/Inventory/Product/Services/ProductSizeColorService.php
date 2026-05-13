<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\ProductSize;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSizeColorService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    public function set(ProductSize $productSize, int $colorId, array $data, bool $updateMaster = true): void
    {
        if (! array_key_exists('stock', $data)) {
            return;
        }

        $psId = (int) $productSize->id;

        $master = DB::table('product_size')
            ->where('id', $psId)
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            throw new RuntimeException('No se encontró la talla del producto para actualizar el color.');
        }

        $pivotRow = DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->where('color_id', $colorId)
            ->lockForUpdate()
            ->first();

        $currentColorStock = $pivotRow ? (int) $pivotRow->stock : 0;
        $newStock = (int) $data['stock'];
        $delta = $newStock - $currentColorStock;

        $existingPivot = $pivotRow
            ? [
                'product_size_id' => $pivotRow->product_size_id,
                'color_id' => $pivotRow->color_id,
                'stock' => (int) $pivotRow->stock,
            ]
            : [];

        if ($updateMaster && $delta !== 0) {
            if ($delta > 0) {
                DB::table('product_size')->where('id', $psId)->increment('stock', $delta);
            } else {
                DB::table('product_size')->where('id', $psId)->decrement('stock', -$delta);
            }
        }

        if ($pivotRow) {
            if ($delta !== 0) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $colorId)
                    ->update(['stock' => $newStock]);
            }
        } else {
            DB::table('product_size_color')->insert([
                'product_size_id' => $psId,
                'color_id' => $colorId,
                'stock' => $newStock,
            ]);
        }

        if ($delta === 0 && $pivotRow !== null) {
            return;
        }

        if (! $productSize->relationLoaded('product')) {
            $productSize->load('product');
        }

        $eventType = $existingPivot === [] ? 'CREATED' : 'UPDATED';

        $newData = ['stock' => $newStock, 'size_id_ref' => $productSize->size_id];
        $oldData = $existingPivot !== []
            ? array_merge($existingPivot, ['size_id_ref' => $productSize->size_id])
            : [];

        $this->historyService->logChange(
            $productSize->product,
            'COLOR',
            $colorId,
            $eventType,
            $oldData,
            $newData
        );
    }

    public function remove(ProductSize $productSize, int $colorId): void
    {
        DB::transaction(function () use ($productSize, $colorId): void {
            $psId = (int) $productSize->id;

            $master = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new RuntimeException('No se encontró la talla del producto para eliminar el color.');
            }

            $pivotRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->lockForUpdate()
                ->first();

            $pivotStock = $pivotRow ? (int) $pivotRow->stock : 0;

            $oldPivotSnapshot = null;
            if ($pivotRow !== null) {
                $oldPivotSnapshot = [
                    'product_size_id' => (int) $pivotRow->product_size_id,
                    'color_id' => (int) $pivotRow->color_id,
                    'stock' => $pivotStock,
                ];

                $masterStock = (int) $master->stock;
                if ($masterStock < $pivotStock) {
                    throw new RuntimeException(
                        'Stock maestro insuficiente respecto al color: no se puede eliminar la variante de forma segura.'
                    );
                }

                DB::table('product_size')->where('id', $psId)->decrement('stock', $pivotStock);
            }

            $productSize->productSizeColors()->detach($colorId);
            $productSize->unsetRelation('productSizeColors');

            if ($oldPivotSnapshot !== null) {
                if (! $productSize->relationLoaded('product')) {
                    $productSize->load('product');
                }

                $oldForLog = array_merge($oldPivotSnapshot, ['size_id_ref' => $productSize->size_id]);

                $this->historyService->logChange(
                    $productSize->product,
                    'COLOR',
                    $colorId,
                    'DELETED',
                    $oldForLog,
                    null,
                );
            }
        });
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }
}

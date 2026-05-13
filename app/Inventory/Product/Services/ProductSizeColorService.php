<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Support\StockAvailability;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSizeColorService
{
    protected ProductHistoryService $historyService;

    public function __construct(ProductHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ?int  $pivotStockDelta  Si no es null, suma ese valor al stock del pivote bajo bloqueo maestro→detalle (el maestro solo se ajusta si $updateMaster).
     */
    public function set(ProductSize $productSize, int $colorId, array $data, bool $updateMaster = true, ?int $pivotStockDelta = null): void
    {
        if ($pivotStockDelta === null && ! array_key_exists('stock', $data)) {
            return;
        }

        if ($pivotStockDelta !== null) {
            if ($pivotStockDelta === 0) {
                return;
            }
            $resolve = static fn (int $current): int => $current + $pivotStockDelta;
        } else {
            $target = (int) $data['stock'];
            $resolve = static fn (int $current): int => $target;
        }

        $this->lockMasterThenApplyColorStock($productSize, $colorId, $resolve, $updateMaster);
    }

    /**
     * Maestro (product_size) bajo lockForUpdate primero; después pivote (product_size_color).
     *
     * @param  Closure(int): int  $resolveNewStock  Recibe stock actual del pivote bajo bloqueo; devuelve stock objetivo.
     */
    private function lockMasterThenApplyColorStock(
        ProductSize $productSize,
        int $colorId,
        Closure $resolveNewStock,
        bool $updateMaster,
    ): void {
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
        $newStock = $resolveNewStock($currentColorStock);
        $delta = $newStock - $currentColorStock;

        if ($delta < 0) {
            StockAvailability::assertCanDecrement($currentColorStock, -$delta);
        }

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
                StockAvailability::assertCanDecrement((int) $master->stock, -$delta);
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

        $pivotFresh = DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->where('color_id', $colorId)
            ->lockForUpdate()
            ->first();

        if ($pivotFresh === null) {
            throw new RuntimeException(
                'No se pudo leer el stock de color tras la mutación para auditoría.'
            );
        }

        $eventType = $existingPivot === [] ? 'CREATED' : 'UPDATED';

        $newData = ['stock' => (int) $pivotFresh->stock, 'size_id_ref' => $productSize->size_id];
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

            if ($pivotRow === null) {
                $productSize->unsetRelation('productSizeColors');

                return;
            }

            $pivotStock = (int) $pivotRow->stock;

            if ($pivotStock > 0) {
                throw new RuntimeException(
                    'No se puede eliminar un color que aún tiene stock.',
                );
            }

            $oldPivotSnapshot = [
                'product_size_id' => (int) $pivotRow->product_size_id,
                'color_id' => (int) $pivotRow->color_id,
                'stock' => $pivotStock,
            ];

            DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $colorId)
                ->delete();

            $productSize->unsetRelation('productSizeColors');

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
        });
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }
}

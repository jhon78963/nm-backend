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
     *                                 Con $updateMaster true (formularios/API de color), el maestro solo sube ante desborde:
     *                                 la suma de pivotes supera al maestro y además esa suma aumentó con esta operación.
     */
    public function set(ProductSize $productSize, int $colorId, array $data, bool $updateMaster = true, ?int $pivotStockDelta = null, ?string $auditReason = null): void
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

        $this->lockMasterThenApplyColorStock($productSize, $colorId, $resolve, $updateMaster, $auditReason);
    }

    /**
     * Maestro (product_size) bajo lockForUpdate primero; pivote después.
     * Con `updateMaster` true: el maestro sólo sube si SUM(pivotes) supera al maestro y la suma total
     * aumentó respecto al instante previo a este cambio (desborde por incremento, no reconciliación bajando hijos).
     *
     * @param  Closure(int): int  $resolveNewStock  Recibe stock actual del pivote bajo bloqueo; devuelve stock objetivo.
     */
    private function lockMasterThenApplyColorStock(
        ProductSize $productSize,
        int $colorId,
        Closure $resolveNewStock,
        bool $updateMaster,
        ?string $auditReason = null,
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

        $sumPivotBefore = (int) DB::table('product_size_color')
            ->where('product_size_id', $psId)
            ->sum('stock');

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

        if ($updateMaster) {
            $sumPivotAfter = (int) DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->sum('stock');

            $masterFresh = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            $masterStock = $masterFresh !== null ? (int) $masterFresh->stock : 0;

            if (
                $masterFresh !== null
                && $sumPivotAfter > $masterStock
                && $sumPivotAfter > $sumPivotBefore
            ) {
                DB::table('product_size')
                    ->where('id', $psId)
                    ->update(['stock' => $sumPivotAfter]);
            }
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
            $newData,
            $auditReason,
        );
    }

    public function remove(ProductSize $productSize, int $colorId, ?string $auditReason = null): void
    {
        DB::transaction(function () use ($productSize, $colorId, $auditReason): void {
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
                $auditReason,
            );
        });
    }

    /**
     * Trasladar todo el stock del pivote origen al color destino (misma talla).
     * Útil cuando el nombre/catálogo del color no coincide con lo físico (ej. vino → guinda).
     *
     * @throws RuntimeException Si no hay pivote origen, sin stock, o colores iguales.
     */
    public function replacePivotColor(ProductSize $productSize, int $fromColorId, int $toColorId, ?string $auditReason = null): void
    {
        if ($fromColorId === $toColorId) {
            throw new RuntimeException('El color destino debe ser distinto al actual.');
        }

        DB::transaction(function () use ($productSize, $fromColorId, $toColorId, $auditReason): void {
            $psId = (int) $productSize->id;

            $master = DB::table('product_size')
                ->where('id', $psId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new RuntimeException('No se encontró la talla del producto para reemplazar color.');
            }

            $sortedColorIds = [$fromColorId, $toColorId];
            sort($sortedColorIds);
            foreach ($sortedColorIds as $cid) {
                DB::table('product_size_color')
                    ->where('product_size_id', $psId)
                    ->where('color_id', $cid)
                    ->lockForUpdate()
                    ->first();
            }

            $fromRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $fromColorId)
                ->first();

            if ($fromRow === null) {
                throw new RuntimeException('Este color no está asignado a la talla seleccionada.');
            }

            $s = (int) $fromRow->stock;
            if ($s <= 0) {
                throw new RuntimeException('No hay stock en el color origen para trasladar.');
            }

            $toRow = DB::table('product_size_color')
                ->where('product_size_id', $psId)
                ->where('color_id', $toColorId)
                ->first();
            $t = $toRow ? (int) $toRow->stock : 0;

            $this->set($productSize, $fromColorId, ['stock' => 0], true, null, $auditReason);
            $this->remove($productSize, $fromColorId, $auditReason);
            $this->set($productSize, $toColorId, ['stock' => $t + $s], true, null, $auditReason);
        });
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }
}

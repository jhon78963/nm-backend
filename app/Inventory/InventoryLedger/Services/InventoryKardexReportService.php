<?php

namespace App\Inventory\InventoryLedger\Services;

use App\Inventory\Color\Models\Color;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\InventoryLedger\Requests\InventoryKardexReportRequest;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Warehouse\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InventoryKardexReportService
{
    /**
     * @return array{
     *     movements: Collection<int, InventoryMovement>,
     *     meta: array<string, mixed>
     * }
     */
    public function buildReport(InventoryKardexReportRequest $request): array
    {
        $user = $request->user();
        $warehouseId = (int) $request->validated('warehouse_id');
        $productId = (int) $request->validated('product_id');
        $productSizeId = (int) $request->validated('product_size_id');
        /** @var int|null $colorId */
        $colorId = $request->validated('color_id');

        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $tenantId = (int) $warehouse->tenant_id;

        if ($user !== null && $user->tenant_id !== null && (int) $user->tenant_id !== $tenantId) {
            abort(403, 'El almacén no pertenece al tenant del usuario autenticado.');
        }

        $rangeStart = Carbon::parse($request->validated('fecha_inicio'))->startOfDay();
        $rangeEnd = Carbon::parse($request->validated('fecha_fin'))->endOfDay();

        $product = Product::query()->findOrFail($productId);
        $productSize = ProductSize::query()
            ->with(['size', 'product'])
            ->findOrFail($productSizeId);

        if ((int) $productSize->product_id !== $productId) {
            abort(422, 'La talla indicada no corresponde al producto.');
        }

        $openingMovement = $this->openingMovementQuery(
            $tenantId,
            $warehouseId,
            $productSizeId,
            $colorId,
            $productId,
            $rangeStart,
        )
            ->first();

        $openingBalanceQuantity = $openingMovement !== null
            ? (int) $openingMovement->balance_after_movement
            : 0;

        $movements = $this->movementsInRangeQuery(
            $tenantId,
            $warehouseId,
            $productSizeId,
            $colorId,
            $productId,
            $rangeStart,
            $rangeEnd,
        )
            ->with([
                'productSize.size',
                'productSize.product',
                'color',
                'createdBy',
                'reference',
            ])
            ->get();

        $closingBalanceQuantity = $openingBalanceQuantity;
        if ($movements->isNotEmpty()) {
            $closingBalanceQuantity = (int) $movements->last()->balance_after_movement;
        }

        $colorSummary = null;
        if ($colorId !== null) {
            $colorModel = Color::query()->find($colorId);
            $colorSummary = $colorModel !== null
                ? ['id' => (int) $colorModel->id, 'description' => $colorModel->description]
                : ['id' => $colorId, 'description' => null];
        }

        return [
            'movements' => $movements,
            'meta' => [
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouse->name,
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_size_id' => $productSizeId,
                'size' => $productSize->size !== null
                    ? [
                        'id' => (int) $productSize->size->id,
                        'description' => $productSize->size->description,
                    ]
                    : null,
                'color_id' => $colorId,
                'color' => $colorSummary,
                'fecha_inicio' => $rangeStart->toDateString(),
                'fecha_fin' => $rangeEnd->toDateString(),
                'opening_balance_quantity' => $openingBalanceQuantity,
                'closing_balance_quantity' => $closingBalanceQuantity,
                'movements_count' => $movements->count(),
            ],
        ];
    }

    /**
     * @return Builder<InventoryMovement>
     */
    private function baseLedgerQuery(
        int $tenantId,
        int $warehouseId,
        int $productSizeId,
        ?int $colorId,
        int $productId,
    ): Builder {
        $q = InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_size_id', $productSizeId)
            ->whereHas(
                'productSize',
                static fn ($rel) => $rel->where('product_id', $productId),
            );

        if ($colorId === null) {
            $q->whereNull('color_id');
        } else {
            $q->where('color_id', $colorId);
        }

        return $q;
    }

    /**
     * @return Builder<InventoryMovement>
     */
    private function openingMovementQuery(
        int $tenantId,
        int $warehouseId,
        int $productSizeId,
        ?int $colorId,
        int $productId,
        Carbon $rangeStart,
    ): Builder {
        return $this->baseLedgerQuery($tenantId, $warehouseId, $productSizeId, $colorId, $productId)
            ->where('occurred_at', '<', $rangeStart)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /**
     * @return Builder<InventoryMovement>
     */
    private function movementsInRangeQuery(
        int $tenantId,
        int $warehouseId,
        int $productSizeId,
        ?int $colorId,
        int $productId,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): Builder {
        return $this->baseLedgerQuery($tenantId, $warehouseId, $productSizeId, $colorId, $productId)
            ->whereBetween('occurred_at', [$rangeStart, $rangeEnd])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->select([
                'inventory_movements.id',
                'inventory_movements.tenant_id',
                'inventory_movements.warehouse_id',
                'inventory_movements.product_size_id',
                'inventory_movements.color_id',
                'inventory_movements.direction',
                'inventory_movements.quantity',
                'inventory_movements.movement_type',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'inventory_movements.balance_after_movement',
                'inventory_movements.occurred_at',
                'inventory_movements.created_by_user_id',
            ]);
    }
}

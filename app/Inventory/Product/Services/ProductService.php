<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Shared\Foundation\Services\ModelService;
use App\Shared\Foundation\Support\AuthenticatedUserWarehouseResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProductService extends ModelService
{
    protected ProductHistoryService $historyService;
    public function __construct(
        Product $product,
        ProductHistoryService $historyService,
    ) {
        parent::__construct($product);
        $this->historyService = $historyService;
    }


    public function create(array $data): Model
    {
        $warehouseId = $data['warehouse_id'] ?? null;
        unset($data['warehouse_id'], $data['id']);

        $product = parent::create($data);

        if ($warehouseId !== null) {
            $product->warehouse_id = (int) $warehouseId;
            $product->save();
        }

        $this->historyService->logChange(
            $product,
            'PRODUCT',
            $product->id,
            'CREATED',
            null,
            $product->toArray()
        );

        return $product;
    }

    public function update(Model $model, array $data): Model
    {
        $warehouseId = $data['warehouse_id'] ?? null;
        unset($data['warehouse_id'], $data['id']);

        $oldData = $model->toArray();
        $product = parent::update($model, $data);

        if ($warehouseId !== null) {
            $product->warehouse_id = (int) $warehouseId;
            $product->save();
        }

        $newData = $product->fresh()->toArray();
        $this->historyService->logChange(
            $product,
            'PRODUCT',
            $product->id,
            'UPDATED',
            $oldData,
            $newData
        );

        return $product;
    }

    public function delete(Model $model): void
    {
        $oldData = $model->toArray();
        parent::delete($model);
        $this->historyService->logChange(
            $model,
            'PRODUCT',
            $model->id,
            'DELETED',
            $oldData,
            null
        );
    }

    public function findBySkuForPos(string $barcode): ?array
    {
        $product = $this->model
            ->with([
                'productSizes' => static fn ($query) => $query->orderBy('size_id'),
                'productSizes.size',
                'productSizes.productSizeColors',
            ])
            ->where('barcode', $barcode)
            ->where('is_deleted', false)
            ->first();

        if (! $product) {
            $sizeMatch = ProductSize::query()
                ->where('barcode', $barcode)
                ->first();

            if ($sizeMatch !== null) {
                $product = $this->model
                    ->with([
                        'productSizes' => static fn ($query) => $query->orderBy('size_id'),
                        'productSizes.size',
                        'productSizes.productSizeColors',
                    ])
                    ->where('id', $sizeMatch->product_id)
                    ->where('is_deleted', false)
                    ->first();
            }
        }

        if (! $product) {
            return null;
        }

        $product->loadMissing(['sizes']);

        // Una consulta sobre `product_size`: evita leer sale_price ya en NULL por hidratación eager.
        $salePriceByPsId = DB::table('product_size')
            ->where('product_id', $product->id)
            ->pluck('sale_price', 'id');

        $salePriceFor = static function (
            ProductSize $pSize,
            Product $product,
        ) use ($salePriceByPsId): float {
            $pid = (int) $pSize->id;
            $sale = $salePriceByPsId->get($pid);
            if ($sale === null) {
                $sale = $salePriceByPsId->get((string) $pid);
            }

            if (($sale === null || $sale === '') && $pSize->size_id) {
                $sizeRow = $product->sizes->firstWhere('id', (int) $pSize->size_id);
                $sale = $sizeRow?->pivot?->sale_price ?? $sale;
            }

            if ($sale === null || $sale === '') {
                $raw = $pSize->getRawOriginal('sale_price');
                $sale = $raw;
            }

            if ($sale === null || $sale === '') {
                return 0.0;
            }

            return (float) $sale;
        };

        // ---------------------------------------------------------
        // FASE 2: MAPEO DE DATOS (Tu lógica original)
        // ---------------------------------------------------------
        $variantsMap = [];
        $basePrice = null;

        foreach ($product->productSizes as $pSize) {
            if (! $pSize->size) {
                continue;
            }

            $tallaNombre = $pSize->size->description;
            $currentPrice = $salePriceFor($pSize, $product);
            $currentSku = $pSize->barcode ?? '';
            $productWarehouseId = (int) ($product->warehouse_id ?? 0);
            $warehouseId = AuthenticatedUserWarehouseResolver::resolveForPosInventory($productWarehouseId);

            if ($currentPrice > 0) {
                $basePrice =
                    $basePrice === null
                        ? $currentPrice
                        : min($basePrice, $currentPrice);
            }

            if (! isset($variantsMap[$tallaNombre])) {
                $variantsMap[$tallaNombre] = [];
            }

            $hasColorVariants = false;
            if ($pSize->productSizeColors && $pSize->productSizeColors->count() > 0) {
                foreach ($pSize->productSizeColors as $color) {
                    $qty = InventoryBalanceLookup::quantityFor($warehouseId, (int) $pSize->id, (int) $color->id);

                    if ($qty > 0) {
                        $hasColorVariants = true;
                        $variantsMap[$tallaNombre][] = [
                            'product_size_id' => $pSize->id,
                            'color_id' => $color->id,
                            'colorName' => $color->description,
                            'hex' => $color->hash ?? '#000000',
                            'inventory' => [
                                'available_quantity' => $qty,
                                'warehouse_id' => $warehouseId,
                            ],
                            'price' => $currentPrice,
                            'sku' => $currentSku,
                        ];
                    }
                }
            }

            if (! $hasColorVariants) {
                $qtyMaster = InventoryBalanceLookup::quantityFor($warehouseId, (int) $pSize->id, null);
                if ($qtyMaster > 0) {
                    $variantsMap[$tallaNombre][] = [
                        'product_size_id' => $pSize->id,
                        'color_id' => 0,
                        'colorName' => 'Único',
                        'hex' => '#E5E7EB',
                        'inventory' => [
                            'available_quantity' => $qtyMaster,
                            'warehouse_id' => $warehouseId,
                        ],
                        'price' => $currentPrice,
                        'sku' => $currentSku,
                    ];
                }
            }
        }

        return [
            'id'        => $product->id,
            'sku'       => $barcode,
            'name'      => $product->name,
            'basePrice' => (float) ($basePrice ?? 0),
            'variants'  => $variantsMap
        ];
    }
}

<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Shared\Foundation\Services\ModelService;
use Illuminate\Database\Eloquent\Model;

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
        $product = parent::create($data);

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
        $oldData = $model->toArray();
        $product = parent::update($model, $data);
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
        // ---------------------------------------------------------
        // FASE 1: BÚSQUEDA OPTIMIZADA (Para evitar colgar la BD)
        // ---------------------------------------------------------
        $product = $this->model
            ->with([
                'productSizes.size',
                'productSizes.productSizeColors'
            ])
            ->where('barcode', $barcode)
            ->where('is_deleted', false)
            ->first();

        if (!$product) {
            $sizeMatch = ProductSize::where('barcode', $barcode)->first();
            if ($sizeMatch) {
                $product = $this->model
                    ->with([
                        'productSizes.size',
                        'productSizes.productSizeColors'
                    ])
                    ->where('id', $sizeMatch->product_id) // Asumiendo que la FK es product_id
                    ->where('is_deleted', false)
                    ->first();
            }
        }

        if (!$product) {
            return null;
        }

        // ---------------------------------------------------------
        // FASE 2: MAPEO DE DATOS (Tu lógica original)
        // ---------------------------------------------------------
        $variantsMap = [];
        $basePrice = 0;

        foreach ($product->productSizes as $pSize) {
            if (!$pSize->size) {
                continue;
            }

            $tallaNombre = $pSize->size->description;
            $currentPrice = (float) ($pSize->sale_price ?? 0);
            $currentSku = $pSize->barcode ?? '';

            if ($basePrice == 0) {
                $basePrice = $currentPrice;
            }

            if (!isset($variantsMap[$tallaNombre])) {
                $variantsMap[$tallaNombre] = [];
            }

            $hasColorVariants = false;
            if ($pSize->productSizeColors && $pSize->productSizeColors->count() > 0) {
                foreach ($pSize->productSizeColors as $color) {
                    $stock = $color->pivot->stock;

                    if ($stock > 0) {
                        $hasColorVariants = true;
                        $variantsMap[$tallaNombre][] = [
                            'product_size_id' => $pSize->id,
                            'color_id'      => $color->id,
                            'colorName'     => $color->description,
                            'hex'           => $color->hash ?? '#000000',
                            'stock'         => $stock,
                            'price'         => $currentPrice,
                            'sku'           => $currentSku
                        ];
                    }
                }
            }

            if (!$hasColorVariants && $pSize->stock > 0) {
                $variantsMap[$tallaNombre][] = [
                    'product_size_id' => $pSize->id,
                    'color_id'      => 0,
                    'colorName'     => 'Único',
                    'hex'           => '#E5E7EB',
                    'stock'         => (int) $pSize->stock,
                    'price'         => $currentPrice,
                    'sku'           => $currentSku
                ];
            }
        }

        return [
            'id'        => $product->id,
            'sku'       => $product->barcode ?? $barcode,
            'name'      => $product->name,
            'basePrice' => (float) $basePrice,
            'variants'  => $variantsMap
        ];
    }
}

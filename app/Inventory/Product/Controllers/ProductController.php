<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Requests\ProductCreateRequest;
use App\Inventory\Product\Requests\ProductUpdateRequest;
use App\Inventory\Product\Resources\ProductResource;
use App\Inventory\Product\Services\ProductExportService;
use App\Inventory\Product\Services\ProductImportService;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\Product\Support\ProductBarcodeSearch;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected SharedService $sharedService,
        protected ProductExportService $productExportService,
        protected ProductImportService $productImportService,
    ) {}

    public function create(ProductCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $data['warehouse_id'] = $this->resolveWarehouseIdForCreate($request, $data);
            $product = $this->productService->create($data);

            return response()->json([
                'message'   => 'Product created successfully.',
                'productId' => $product->id,
            ], 201);
        });
    }

    public function update(ProductUpdateRequest $request, Product $product): JsonResponse
    {
        return DB::transaction(function () use ($request, $product) {
            $this->productService->validate($product, 'Product');
            $data = $this->sharedService->convertCamelToSnake($request->validated());

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                WarehouseIdForInventoryResolver::assertUserCanAccessWarehouse((int) $data['warehouse_id']);
            }

            $this->productService->update($product, $data);

            return response()->json([
                'message'   => 'Product updated successfully.',
                'productId' => $product->id,
            ]);
        });
    }

    public function delete(Product $product): JsonResponse
    {
        return DB::transaction(function () use ($product) {
            $this->productService->validate($product, 'Product');
            $this->productService->delete($product);

            return response()->json(['message' => 'Product deleted.']);
        });
    }

    public function get(Product $product): JsonResponse
    {
        $this->productService->validate($product, 'Product');
        $warehouseId = WarehouseIdForInventoryResolver::resolve(request(), $product->warehouse_id !== null ? (int) $product->warehouse_id : null);

        $product->load([
            'productSizes' => static fn ($q) => $q->orderBy('id'),
            'media',
        ]);

        if ($warehouseId > 0) {
            $product->loadSum([
                'inventoryBalances as inventory_sum_qty' => static fn ($q) => $q
                    ->where('inventory_balances.warehouse_id', $warehouseId)
                    ->whereNull('inventory_balances.color_id'),
            ], 'quantity');
        } else {
            $product->loadSum([
                'inventoryBalances as inventory_sum_qty' => static fn ($q) => $q->whereNull('inventory_balances.color_id'),
            ], 'quantity');
        }

        return response()->json(new ProductResource($product));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $filters = array_filter([
            'gender_id' => $request->input('genderId'),
        ]);

        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, null);

        $stockSubquery = '(SELECT COALESCE(SUM(quantity), 0) FROM inventory_balances WHERE inventory_balances.product_id = products.id AND inventory_balances.color_id IS NULL';
        $stockSubquery .= $warehouseId > 0
            ? ' AND inventory_balances.warehouse_id = '.(int) $warehouseId
            : '';
        $stockSubquery .= ')';

        $columnSearch = ['id', 'name', 'barcode', 'gender.name', 'productSizes.barcode', $stockSubquery];

        $queryResult = $this->sharedService->query(
            request:      $request,
            entityName:   'Inventory\\Product',
            modelName:    'Product',
            columnSearch: $columnSearch,
            filters:      $filters,
            extendQuery: function ($q) use ($warehouseId) {
                $q = $q->with('media');

                if ($warehouseId > 0) {
                    return $q->withSum([
                        'inventoryBalances as inventory_sum_qty' => static fn ($rel) => $rel
                            ->where('inventory_balances.warehouse_id', $warehouseId)
                            ->whereNull('inventory_balances.color_id'),
                    ], 'quantity');
                }

                return $q->withSum([
                    'inventoryBalances as inventory_sum_qty' => static fn ($rel) => $rel->whereNull('inventory_balances.color_id'),
                ], 'quantity');
            },
            searchFilter: fn ($query, $search, $columns) => ProductBarcodeSearch::apply(
                $query,
                $search,
                is_array($columns) ? $columns : [$columns],
                $this->sharedService,
            ),
        );

        return response()->json(new GetAllCollection(
            ProductResource::collection($queryResult['collection']),
            $queryResult['total'],
            $queryResult['pages'],
        ));
    }

    public function export(Request $request): StreamedResponse
    {
        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, null);

        if ($warehouseId < 1) {
            abort(400, 'No se pudo determinar el almacén.');
        }

        return $this->productExportService->export($warehouseId);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,ods|max:10240',
        ]);

        $warehouseId = WarehouseIdForInventoryResolver::resolve($request, null);

        if ($warehouseId < 1) {
            abort(400, 'No se pudo determinar el almacén.');
        }

        $result = $this->productImportService->import($request->file('file'), $warehouseId);

        return response()->json([
            'message' => "Importación completada: {$result['updated']} filas actualizadas, {$result['skipped']} omitidas.",
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors'  => $result['errors'],
        ]);
    }

    /**
     * Prioridad: payload validado → almacén del usuario autenticado → cabecera/query del request.
     */
    private function resolveWarehouseIdForCreate(ProductCreateRequest $request, array $data): int
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($warehouseId < 1) {
            $warehouseId = (int) (Auth::user()?->warehouse_id ?? 0);
        }
        if ($warehouseId < 1) {
            $warehouseId = WarehouseIdForInventoryResolver::resolve($request, null);
        }
        if ($warehouseId < 1) {
            throw new InvalidArgumentException(
                'Debe indicar un almacén válido (warehouseId) para crear el producto.',
            );
        }

        WarehouseIdForInventoryResolver::assertUserCanAccessWarehouse($warehouseId);

        return $warehouseId;
    }
}

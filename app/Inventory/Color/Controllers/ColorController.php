<?php

namespace App\Inventory\Color\Controllers;

use App\Inventory\Color\Models\Color;
use App\Inventory\Color\Requests\ColorCreateRequest;
use App\Inventory\Color\Requests\ColorUpdateRequest;
use App\Inventory\Color\Resources\AutocompleteColorResource;
use App\Inventory\Color\Resources\ColorResource;
use App\Inventory\Color\Resources\ColorSelectedResource;
use App\Inventory\Color\Resources\SizeResource;
use App\Inventory\Color\Services\ColorService;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Size\Requests\GetAllSelectedRequest;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use DB;

class ColorController extends Controller
{
    protected ColorService $colorService;
    protected SharedService $sharedService;

    public function __construct(ColorService $colorService, SharedService $sharedService)
    {
        $this->colorService = $colorService;
        $this->sharedService = $sharedService;
    }

    public function create(ColorCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newColor = $this->sharedService->convertCamelToSnake($request->validated());
            $this->colorService->create($newColor);
            DB::commit();
            return response()->json(['message' => 'Color created.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function delete(Color $color): JsonResponse
    {
        DB::beginTransaction();
        try {
            $colorValidated = $this->colorService->validate($color, 'Color');
            $this->colorService->delete($colorValidated);
            DB::commit();
            return response()->json(['message' => 'Color deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function get(Color $color): JsonResponse
    {
        $colorValidated = $this->colorService->validate($color, 'Color');
        return response()->json(new ColorResource($colorValidated));
    }

    public function getSizes(GetAllSelectedRequest $request): JsonResponse
    {
        $productId = $request->input('productId');
        $size = $request->input('size');

        $sizes = ProductSize::query()
            ->join('sizes as s', 'product_size.size_id', '=', 's.id')
            ->where('product_size.product_id', $productId)
            ->when(
                $size,
                fn(Builder $query): Builder =>
                $query->whereRaw('LOWER(s.description) LIKE ?', ['%' . strtolower($size) . '%'])
            )
            ->select([
                's.id',
                'product_size.id as productSizeId',
                's.description',
                'product_size.stock',
                'product_size.barcode',
                'product_size.purchase_price',
                'product_size.sale_price',
                'product_size.min_sale_price',
            ])
            ->orderByRaw("CASE WHEN s.description ~ '^[0-9]+$' THEN s.description::integer ELSE NULL END ASC")
            ->orderBy('s.id', 'asc')
            ->get();

        return response()->json(SizeResource::collection($sizes));
    }

    /**
     * Catálogo completo de colores con bandera `isExists` / `stock` según `product_size_color`.
     */
    public function getAllSelected(GetAllSelectedRequest $request): JsonResponse
    {
        $productSizeId = $this->resolveProductSizeId(
            $request->integer('productId'),
            $request->integer('sizeId'),
        );

        $colors = $this->buildCatalogColorsForProductSize($productSizeId);

        return response()->json(ColorSelectedResource::collection($colors));
    }

    /**
     * Solo colores que ya tienen fila en `product_size_color` (stock no nulo en inventario por talla).
     * Útil en compras para elegir variantes ya existentes sin listar todo el catálogo.
     */
    public function getAllSelectedAttached(GetAllSelectedRequest $request): JsonResponse
    {
        $productSizeId = $this->resolveProductSizeId(
            $request->integer('productId'),
            $request->integer('sizeId'),
        );

        $colors = $this->buildCatalogColorsForProductSize($productSizeId)
            ->filter(fn (Color $color): bool => $color->isExists === true)
            ->values();

        return response()->json(ColorSelectedResource::collection($colors));
    }

    private function resolveProductSizeId(int $productId, int $sizeId): ?int
    {
        if ($productId < 1 || $sizeId < 1) {
            return null;
        }

        $id = DB::table('product_size')
            ->where('product_id', $productId)
            ->where('size_id', $sizeId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return Collection<int, Color>
     */
    private function buildCatalogColorsForProductSize(?int $productSizeId): Collection
    {
        $productSizeColors = collect();

        if ($productSizeId) {
            $productSizeColors = DB::table('product_size_color')
                ->where('product_size_id', '=', $productSizeId)
                ->get()
                ->keyBy('color_id');
        }

        return Color::where('is_deleted', '=', false)
            ->orderBy('description', 'asc')
            ->get()
            ->map(function ($color) use ($productSizeColors, $productSizeId): Color {
                if ($productSizeColors->has($color->id)) {
                    $color->isExists = true;
                    $color->stock = $productSizeColors[$color->id]->stock;
                } else {
                    $color->isExists = false;
                    $color->stock = null;
                }

                $color->productSizeId = $productSizeId;

                return $color;
            })
            ->sortBy(function ($color): array {
                if ($color->isExists) {
                    $priority = ($color->stock > 0) ? 0 : 1;
                } else {
                    $priority = 2;
                }

                return [$priority, strtolower($color->description)];
            })
            ->values();
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Inventory\\Color',
            'Color',
            ['id', 'description', 'hash']
        );
        return response()->json(new GetAllCollection(
            ColorResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function getAllAutocomplete(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Inventory\\Color',
            'Color',
            'description'
        );
        return response()->json(
            AutocompleteColorResource::collection($query['collection'])
        );
    }

    public function update(ColorUpdateRequest $request, Color $color): JsonResponse
    {
        DB::beginTransaction();
        try {
            $editColor = $this->sharedService->convertCamelToSnake($request->validated());
            $colorValidated = $this->colorService->validate($color, 'Color');
            $this->colorService->update($colorValidated, $editColor);
            DB::commit();
            return response()->json(['message' => 'Color updated.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}

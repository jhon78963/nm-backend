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

        $sizes = ProductSize::join('sizes as s', 'product_size.size_id', '=', 's.id')
            ->where('product_size.product_id', $productId)
            ->when(
                $size,
                fn(Builder $query): Builder =>
                $query->whereRaw('LOWER(s.description) LIKE ?', ['%' . strtolower($size) . '%'])
            )
            ->select('s.id', 'product_size.id as productSizeId', 's.description', 'product_size.stock')
            ->orderByRaw("CASE WHEN s.description ~ '^[0-9]+$' THEN s.description::integer ELSE NULL END ASC")
            ->orderBy('s.id', 'asc')
            ->get();

        return response()->json(SizeResource::collection($sizes));
    }

    public function getAllSelected(GetAllSelectedRequest $request): JsonResponse
    {
        $productId = $request->input('productId');
        $sizeId = $request->input('sizeId');

        $productSizeId = DB::table('product_size')
            ->where('product_id', $productId)
            ->where('size_id', $sizeId)
            ->value('id');

        $productSizeColors = collect();

        if ($productSizeId) {
            $productSizeColors = DB::table('product_size_color')
                ->where('product_size_id', '=', $productSizeId)
                ->get()
                ->keyBy('color_id');
        }

        $colors = Color::where('is_deleted', '=', false)
            ->orderBy('description', 'asc') // Ordenamos alfabéticamente por nombre del color aquí
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

        return response()->json(ColorSelectedResource::collection($colors));
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

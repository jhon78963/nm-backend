<?php

namespace App\Inventory\Size\Controllers;

use App\Inventory\Size\Models\Size;
use App\Inventory\Size\Models\SizeType;
use App\Inventory\Size\Requests\GetAllSelectedRequest;
use App\Inventory\Size\Requests\SizeCreateRequest;
use App\Inventory\Size\Requests\SizeUpdateRequest;
use App\Inventory\Size\Resources\AutocompleteSizeResource;
use App\Inventory\Size\Resources\SizeResource;
use App\Inventory\Size\Resources\SizeSelectedResource;
use App\Inventory\Size\Resources\SizeTypeResource;
use App\Inventory\Size\Services\SizeService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\GetAllRequest;
use App\Shared\Resources\GetAllCollection;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;

class SizeController extends Controller
{
    protected SizeService $sizeService;
    protected SharedService $sharedService;

    public function __construct(SizeService $sizeService, SharedService $sharedService)
    {
        $this->sizeService = $sizeService;
        $this->sharedService = $sharedService;
    }

    public function create(SizeCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newSize = $this->sharedService->convertCamelToSnake($request->validated());
            $this->sizeService->create($newSize);
            DB::commit();
            return response()->json(['message' => 'Size created.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function delete(Size $size): JsonResponse
    {
        DB::beginTransaction();
        try {
            $sizeValidated = $this->sizeService->validate($size, 'Size');
            $this->sizeService->delete($sizeValidated);
            DB::commit();
            return response()->json(['message' => 'Size deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function get(Size $size): JsonResponse
    {
        $sizeValidated = $this->sizeService->validate($size, 'Size');
        return response()->json(new SizeResource($sizeValidated));
    }

    public function getAutocomplete(Size $size): JsonResponse
    {
        $sizeValidated = $this->sizeService->validate($size, 'Size');
        return response()->json(new AutocompleteSizeResource($sizeValidated));
    }

    public function getSizeType(): JsonResponse
    {
        $sizeType = SizeType::where('is_deleted', '=', false)->get();
        return response()->json(SizeTypeResource::collection($sizeType));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $specificFilters = [];
        $sizeTypeIdValue = $request->query('sizeTypeId');
        if ($request->has('sizeTypeId') && $sizeTypeIdValue !== '') {
            $specificFilters['size_type_id'] = $sizeTypeIdValue;
        }
        $query = $this->sharedService->query(
            request: $request,
            entityName: 'Inventory\\Size',
            modelName: 'Size',
            columnSearch: ['id', 'description', 'sizeType.description'],
            filters: $specificFilters
        );
        return response()->json(new GetAllCollection(
            SizeResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }

    public function getAllSelected(GetAllSelectedRequest $request): JsonResponse
    {
        $productId = $request->input('productId');
        $sizeTypeIdRaw = $request->input('sizeTypeId');
        $sizeTypeIds = $sizeTypeIdRaw ? explode(',', $sizeTypeIdRaw) : [];
        $productSizes = DB::table('product_size')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('size_id');

        $sizes = Size::whereIn('size_type_id', $sizeTypeIds)
            ->get()
            ->map(function ($size) use ($productSizes): Size {
                if ($productSizes->has($size->id)) {
                    $size->isExists = true;
                    $size->barcode = $productSizes[$size->id]->barcode;
                    $size->stock = $productSizes[$size->id]->stock;
                    $size->purchasePrice = $productSizes[$size->id]->purchase_price;
                    $size->salePrice = $productSizes[$size->id]->sale_price;
                    $size->minSalePrice = $productSizes[$size->id]->min_sale_price;
                } else {
                    $size->isExists = false;
                    $size->barcode = null;
                    $size->stock = null;
                    $size->purchasePrice = null;
                    $size->salePrice = null;
                    $size->minSalePrice = null;
                }
                return $size;
            })->sortBy(
                fn($size): mixed => $size->stock === null ? PHP_INT_MAX : $size->id
            )->values();

        return response()->json(SizeSelectedResource::collection($sizes));
    }

    public function getAllAutocomplete(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Inventory\\Size',
            'Size',
            'description'
        );
        return response()->json(
            AutocompleteSizeResource::collection($query['collection'])
        );
    }

    public function update(SizeUpdateRequest $request, Size $size): JsonResponse
    {
        DB::beginTransaction();
        try {
            $editSize = $this->sharedService->convertCamelToSnake($request->validated());
            $sizeValidated = $this->sizeService->validate($size, 'Size');
            $this->sizeService->update($sizeValidated, $editSize);
            DB::commit();
            return response()->json(['message' => 'Size updated.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}

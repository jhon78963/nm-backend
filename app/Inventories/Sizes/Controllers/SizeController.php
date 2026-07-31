<?php

namespace App\Inventories\Sizes\Controllers;

use App\Inventories\Sizes\Models\Size;
use App\Inventories\Sizes\Models\SizeType;
use App\Inventories\Sizes\Requests\GetAllSelectedRequest;
use App\Inventories\Sizes\Requests\SizeCreateRequest;
use App\Inventories\Sizes\Requests\SizeUpdateRequest;
use App\Inventories\Sizes\Resources\AutocompleteSizeResource;
use App\Inventories\Sizes\Resources\SizeResource;
use App\Inventories\Sizes\Resources\SizeSelectedResource;
use App\Inventories\Sizes\Resources\SizeTypeResource;
use App\Inventories\Sizes\Services\SizeService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SizeController extends Controller
{
    public function __construct(
        protected SizeService $sizeService,
        protected SharedService $sharedService,
    ) {}

    public function create(SizeCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->sizeService->create($data);

            return response()->json(['message' => 'Size created successfully.'], 201);
        });
    }

    public function update(SizeUpdateRequest $request, Size $size): JsonResponse
    {
        return DB::transaction(function () use ($request, $size) {
            $this->sizeService->validate($size, 'Size');

            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->sizeService->update($size, $data);

            return response()->json(['message' => 'Size updated successfully.']);
        });
    }

    public function delete(Size $size): JsonResponse
    {
        return DB::transaction(function () use ($size): JsonResponse {
            $this->sizeService->validate($size, 'Size');
            $this->sizeService->delete($size);

            return response()->json(['message' => 'Size deleted successfully.']);
        });
    }

    public function get(Size $size): JsonResponse
    {
        $this->sizeService->validate($size, 'Size');
        return response()->json(new SizeResource($size));
    }

    public function getAutocomplete(Size $size): JsonResponse
    {
        $this->sizeService->validate($size, 'Size');
        return response()->json(new AutocompleteSizeResource($size));
    }

    public function getSizeType(): JsonResponse
    {
        $sizeTypes = SizeType::where('is_deleted', false)->get();
        return response()->json(SizeTypeResource::collection($sizeTypes));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $filters = array_filter([
            'size_type_id' => $request->input('sizeTypeId')
        ]);

        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Inventory\\Size',
            modelName:    'Size',
            columnSearch: ['id', 'description', 'sizeType.description'],
            filters:      $filters
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
        $sizeTypeIds = explode(',', $request->input('sizeTypeId', ''));

        $sizes = $this->sizeService->getForProductSelection($productId, $sizeTypeIds);

        return response()->json(SizeSelectedResource::collection($sizes));
    }

    public function getAllAutocomplete(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Inventory\\Size',
            modelName:    'Size',
            columnSearch: 'description'
        );

        return response()->json(
            AutocompleteSizeResource::collection($query['collection'])
        );
    }
}

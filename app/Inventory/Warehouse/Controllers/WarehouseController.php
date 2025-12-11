<?php

namespace App\Inventory\Warehouse\Controllers;

use App\Inventory\Warehouse\Model\Warehouse;
use App\Inventory\Warehouse\Requests\WarehouseCreateRequest;
use App\Inventory\Warehouse\Requests\WarehouseUpdateRequest;
use App\Inventory\Warehouse\Resources\WarehouseResource;
use App\Inventory\Warehouse\Services\WarehouseService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService,
        protected SharedService $sharedService,
    ) {}

    public function create(WarehouseCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->warehouseService->create($data);

            return response()->json(['message' => 'Warehouse created successfully.'], 201);
        });
    }

    public function update(WarehouseUpdateRequest $request, Warehouse $warehouse): JsonResponse
    {
        return DB::transaction(function () use ($request, $warehouse) {
            $this->warehouseService->validate($warehouse, 'Warehouse');

            $data = $this->sharedService->convertCamelToSnake($request->validated());
            $this->warehouseService->update($warehouse, $data);

            return response()->json(['message' => 'Warehouse updated successfully.']);
        });
    }

    public function delete(Warehouse $warehouse): JsonResponse
    {
        return DB::transaction(function () use ($warehouse): JsonResponse {
            $this->warehouseService->validate($warehouse, 'Warehouse');
            $this->warehouseService->delete($warehouse);

            return response()->json(['message' => 'Warehouse deleted successfully.']);
        });
    }

    public function get(Warehouse $warehouse): JsonResponse
    {
        $this->warehouseService->validate($warehouse, 'Warehouse');
        return response()->json(new WarehouseResource($warehouse));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            request:      $request,
            entityName:   'Inventory\\Warehouse',
            modelName:    'Warehouse',
            columnSearch: ['id', 'description'],
        );

        return response()->json(new GetAllCollection(
            WarehouseResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
